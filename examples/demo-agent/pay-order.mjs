#!/usr/bin/env node
/**
 * Pays an EXISTING Shopware order with x402, proving ownership via the order
 * deepLinkCode (spec sections 7.2.0 and 26.3). This is the hand-off used for
 * orders placed outside a Store API session the agent controls - e.g. a UCP
 * checkout completed through the SwagAgenticCommerce plugin, which returns
 * orderId + deepLinkCode but never the internal context token.
 *
 * Usage:
 *   node pay-order.mjs <orderId> <deepLinkCode>
 *
 * Required env (same as agent.mjs):
 *   SW_ACCESS_KEY  sales channel access key
 *   PAYER_PK       payer private key, 0x-prefixed
 * Optional env:
 *   BASE_URL       shop base url (default http://localhost:8000)
 */

import { privateKeyToAccount } from "viem/accounts";
import { wrapFetchWithPayment, decodeXPaymentResponse } from "x402-fetch";

const BASE = process.env.BASE_URL ?? "http://localhost:8000";
const ACCESS_KEY = required("SW_ACCESS_KEY");
const PAYER_PK = required("PAYER_PK");

const [orderId, deepLinkCode] = process.argv.slice(2);
if (!orderId || !deepLinkCode) {
  console.error("Usage: node pay-order.mjs <orderId> <deepLinkCode>");
  process.exit(1);
}

function required(name) {
  const value = process.env[name];
  if (!value) {
    console.error(`Missing required env var ${name}`);
    process.exit(1);
  }
  return value;
}

const payUrl = `${BASE}/store-api/x402/order/${orderId}/pay?deepLinkCode=${encodeURIComponent(deepLinkCode)}`;
const headers = {
  "sw-access-key": ACCESS_KEY,
  "Idempotency-Key": crypto.randomUUID(),
};

// 1. First call without X-PAYMENT: expect HTTP 402 with the requirements.
console.log("=== 1. Requesting payment requirements (expect HTTP 402) ===");
const quoteResponse = await fetch(payUrl, { method: "POST", headers });
const quote = await quoteResponse.json();

if (quoteResponse.status !== 402) {
  console.error(`expected HTTP 402, got ${quoteResponse.status}`);
  console.error(JSON.stringify(quote, null, 2));
  process.exit(1);
}

const requirement = quote.accepts?.[0];
console.log(`order ${quote.shopware?.orderNumber}: ${quote.shopware?.amount?.totalPrice} ${quote.shopware?.amount?.currency}`);
console.log(`required: ${requirement?.maxAmountRequired} atomic on ${requirement?.network}, payTo ${requirement?.payTo}`);

// 2. Sign and retry with X-PAYMENT (x402-fetch handles the 402 handshake).
console.log("\n=== 2. Signing and paying ===");
const account = privateKeyToAccount(PAYER_PK);
console.log(`payer wallet: ${account.address}`);

const fetchWithPayment = wrapFetchWithPayment(fetch, account, BigInt(requirement.maxAmountRequired));
const payResponse = await fetchWithPayment(payUrl, { method: "POST", headers });
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

if (requirement?.network === "base-sepolia" && payResult.payment?.transactionHash) {
  console.log(`\nExplorer: https://sepolia.basescan.org/tx/${payResult.payment.transactionHash}`);
}

console.log(`\nDone: order ${payResult.orderNumber} is ${payResult.status === "paid" ? "PAID" : payResult.status}.`);
