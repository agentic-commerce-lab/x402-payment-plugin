#!/usr/bin/env node
/**
 * Demo agent for the SwagX402Payments plugin (spec section 18).
 *
 * Full flow: capabilities -> product discovery -> cart -> guest registration
 * -> order -> x402 handshake (402 -> sign -> X-PAYMENT) -> settlement check.
 *
 * The payment leg uses the official x402-fetch client, so this exercises the
 * real protocol: EIP-3009 typed-data signing and the 402 retry handshake.
 *
 * Required env:
 *   SW_ACCESS_KEY  sales channel access key (admin -> Sales channel -> API access)
 *   PAYER_PK       payer private key, 0x-prefixed (test key; fund via faucet.circle.com for Base Sepolia)
 * Optional env:
 *   BASE_URL       shop base url               (default http://localhost:8000)
 *   PRODUCT_ID     product to buy              (default: first product found)
 *   COUNTRY_ISO    billing country iso         (default DE)
 *   MAX_ATOMIC     max payment in atomic units (default 10000000 = 10 USDC)
 *   DEEP_LINK_ONLY set to 1 to prove ownership via deepLinkCode instead of context token
 */

import { privateKeyToAccount } from "viem/accounts";
import { wrapFetchWithPayment, decodeXPaymentResponse } from "x402-fetch";

const BASE = process.env.BASE_URL ?? "http://localhost:8000";
const ACCESS_KEY = required("SW_ACCESS_KEY");
const PAYER_PK = required("PAYER_PK");

function required(name) {
  const value = process.env[name];
  if (!value) {
    console.error(`Missing required env var ${name}`);
    process.exit(1);
  }
  return value;
}

let contextToken = null;

async function storeApi(path, { method = "GET", body, headers = {} } = {}) {
  const response = await fetch(BASE + path, {
    method,
    headers: {
      "sw-access-key": ACCESS_KEY,
      "Content-Type": "application/json",
      ...(contextToken ? { "sw-context-token": contextToken } : {}),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const newToken = response.headers.get("sw-context-token");
  if (newToken) {
    contextToken = newToken;
  }

  const text = await response.text();
  const json = text ? JSON.parse(text) : {};

  if (!response.ok) {
    const error = new Error(`${method} ${path} -> HTTP ${response.status}\n${JSON.stringify(json, null, 2)}`);
    error.body = json;
    throw error;
  }

  return json;
}

function step(title) {
  console.log(`\n=== ${title} ===`);
}

// 1. Context + capabilities ---------------------------------------------------
step("1. Context and x402 capabilities");
const context = await storeApi("/store-api/context");
contextToken = context.token ?? contextToken;
console.log("context token acquired");

const capabilities = await storeApi("/store-api/x402/capabilities");
console.log("capabilities:", JSON.stringify(capabilities));
if (!capabilities.schemes?.length) {
  console.error("The x402 plugin is not fully configured for this sales channel (schemes is empty). Configure it first.");
  process.exit(1);
}

// 2. Pick a product and a billing country -------------------------------------
step("2. Product and country discovery");
let productId = process.env.PRODUCT_ID;
if (!productId) {
  const products = await storeApi("/store-api/product", {
    method: "POST",
    body: { limit: 10, filter: [{ type: "equals", field: "active", value: true }] },
  });
  const product = products.elements?.find((candidate) => candidate.available && candidate.calculatedPrice?.totalPrice > 0);
  if (!product) {
    throw new Error("No purchasable product found; set PRODUCT_ID explicitly.");
  }
  productId = product.id;
  console.log(`product: ${product.translated?.name ?? product.name} (${product.calculatedPrice.totalPrice} ${context.currency?.isoCode ?? ""})`);
}

const countryIso = process.env.COUNTRY_ISO ?? "DE";
const countries = await storeApi("/store-api/country", { method: "POST", body: { limit: 100 } });
const country = countries.elements?.find((candidate) => candidate.iso === countryIso) ?? countries.elements?.[0];
if (!country) {
  throw new Error("No billing country available in this sales channel.");
}

// 3. Cart ---------------------------------------------------------------------
step("3. Cart");
const cart = await storeApi("/store-api/checkout/cart/line-item", {
  method: "POST",
  body: { items: [{ type: "product", referencedId: productId, quantity: 1 }] },
});
console.log(`cart total: ${cart.price?.totalPrice}`);

// 4. Guest registration ---------------------------------------------------------
step("4. Guest registration");
const registration = {
  guest: true,
  email: `agent+${Date.now()}@example.test`,
  firstName: "Demo",
  lastName: "Agent",
  acceptedDataProtection: true,
  storefrontUrl: process.env.STOREFRONT_URL ?? BASE,
  billingAddress: {
    street: "Agent Street 1",
    zipcode: "48624",
    city: "Schoeppingen",
    countryId: country.id,
  },
};

try {
  await storeApi("/store-api/account/register", { method: "POST", body: registration });
} catch (error) {
  // storefrontUrl must exactly match a sales channel domain; Shopware lists
  // the valid choices in the violation - retry once with the first one.
  const violation = error.body?.errors?.find((entry) => entry.source?.pointer === "/storefrontUrl");
  const choices = violation?.meta?.parameters?.["{{ choices }}"];
  const validDomain = choices?.match(/https?:\/\/[^"\\,]+/)?.[0];
  if (!validDomain) {
    throw error;
  }
  registration.storefrontUrl = validDomain;
  console.log(`storefrontUrl rejected, retrying with sales channel domain ${validDomain}`);
  await storeApi("/store-api/account/register", { method: "POST", body: registration });
}
console.log("guest customer registered");

// 5. Select the x402 payment method when it is available in the channel --------
step("5. Payment method");
const paymentMethods = await storeApi("/store-api/payment-method", { method: "POST", body: { onlyAvailable: true } });
const x402Method = paymentMethods.elements?.find(
  (method) => method.technicalName === capabilities.paymentMethodTechnicalName,
);
if (x402Method) {
  await storeApi("/store-api/context", { method: "PATCH", body: { paymentMethodId: x402Method.id } });
  console.log("x402 payment method selected for checkout");
} else {
  console.log("x402 method not selectable in this channel - relying on the after-order switch");
}

// 6. Place the order -------------------------------------------------------------
step("6. Order");
const order = await storeApi("/store-api/checkout/order", { method: "POST", body: {} });
console.log(`order ${order.orderNumber} (${order.id}), amount ${order.amountTotal} ${order.currency?.isoCode ?? ""}`);

// 7. Pay via x402 -----------------------------------------------------------------
step("7. x402 payment handshake");
const account = privateKeyToAccount(PAYER_PK);
console.log(`payer wallet: ${account.address}`);

// Authorize exactly the order total (in atomic token units) as the payment cap,
// so the agent can never be charged more than what it ordered. Override with
// MAX_ATOMIC if you want a fixed budget instead.
const decimals = capabilities.networks[0]?.decimals ?? 6;
const maxAtomic = process.env.MAX_ATOMIC
  ? BigInt(process.env.MAX_ATOMIC)
  : BigInt(Math.round(order.amountTotal * 10 ** decimals));
console.log(`payment cap: ${maxAtomic} atomic units`);
const fetchWithPayment = wrapFetchWithPayment(fetch, account, maxAtomic);

const useDeepLink = process.env.DEEP_LINK_ONLY === "1" || !!order.deepLinkCode;
const payUrl = `${BASE}/store-api/x402/order/${order.id}/pay${useDeepLink ? `?deepLinkCode=${encodeURIComponent(order.deepLinkCode)}` : ""}`;

const payResponse = await fetchWithPayment(payUrl, {
  method: "POST",
  headers: {
    "sw-access-key": ACCESS_KEY,
    ...(process.env.DEEP_LINK_ONLY === "1" ? {} : { "sw-context-token": contextToken }),
    "Idempotency-Key": crypto.randomUUID(),
  },
});

const payResult = await payResponse.json();
if (!payResponse.ok) {
  console.error(`payment failed: HTTP ${payResponse.status}`);
  console.error(JSON.stringify(payResult, null, 2));
  process.exit(1);
}

console.log(`status: ${payResult.status}`);
console.log(`transaction state: ${payResult.transactionState}`);
console.log(`settlement tx: ${payResult.payment?.transactionHash}`);

const paymentResponseHeader = payResponse.headers.get("x-payment-response");
if (paymentResponseHeader) {
  console.log("X-PAYMENT-RESPONSE:", JSON.stringify(decodeXPaymentResponse(paymentResponseHeader)));
}

// 8. Verify via the session status endpoint ---------------------------------------
step("8. Session status");
const session = await storeApi(`/store-api/x402/payment-session/${payResult.payment.paymentSessionId}`);
console.log(JSON.stringify(session, null, 2));

if (capabilities.networks?.[0]?.network === "base-sepolia" && payResult.payment?.transactionHash) {
  console.log(`\nExplorer: https://sepolia.basescan.org/tx/${payResult.payment.transactionHash}`);
}

console.log(`\nDone: order ${order.orderNumber} is ${session.status === "settled" ? "PAID" : session.status}.`);
