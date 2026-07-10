# Shopware 6 x402 Payment Plugin Specification

**Document status:** Draft specification  
**Primary focus:** Headless / agentic Store API payment mode  
**Target platform:** Shopware 6.7+  
**Target protocol:** x402 protocol v1, `exact` scheme  
**Prepared for:** Shopware 6 payment extension planning

---

## 1. Executive Summary

This specification describes a Shopware 6 payment plugin that enables x402-based payments, with the primary product focus on **headless and agentic API checkout**.

The plugin should let autonomous clients, AI agents, and other API consumers complete a Shopware checkout by paying a Shopware order transaction through the x402 protocol. The recommended flow is order-first:

```text
Agent builds cart via Store API
→ Agent selects x402 payment method
→ Agent places order
→ Shopware creates open order transaction
→ Agent calls x402 payment route
→ Plugin returns HTTP 402 with payment requirements
→ Agent signs x402 payment payload
→ Agent retries same route with X-PAYMENT header
→ Plugin verifies and settles payment through facilitator
→ Plugin marks Shopware order transaction paid
```

The most important design principle is:

> x402 pays a Shopware order transaction, not a cart and not an arbitrary API request.

Because the order-first flow only needs an open Shopware order transaction, it is intentionally agnostic to **how** the order was created: classic Store API checkout, storefront checkout, or a UCP checkout completed through the `SwagAgenticCommerce` plugin. Section 26 specifies the compatibility contract for installations that run this plugin together with `SwagAgenticCommerce` (UCP integration).

---

## 2. Goals

### 2.1 Primary Goals

- Provide a native Shopware 6 payment method for x402 payments.
- Support headless Store API checkout without storefront redirects.
- Return x402-compatible `402 Payment Required` responses with payment requirements.
- Accept signed x402 payment payloads from agents or API clients.
- Verify and settle payments through an x402 facilitator.
- Mark the Shopware order transaction as `paid` only after successful settlement.
- Prevent replay, double settlement, quote tampering, and cross-order payment reuse.
- Provide a clean foundation for future storefront and one-call agentic purchase flows.
- Coexist cleanly with the `SwagAgenticCommerce` plugin (UCP integration): no route, table, service, or capability conflicts, and support paying orders that were placed through a UCP checkout (see section 26).

### 2.2 Non-Goals for MVP

- Full storefront wallet UI.
- Automated refunds.
- Multi-token routing.
- Dynamic FX unless explicitly required.
- Self-hosted chain settlement infrastructure.
- One-call purchase endpoint that creates the order only after payment.
- Customer-facing crypto wallet onboarding flows.

---

## 3. Research Summary

### 3.1 x402 Protocol

x402 is an HTTP-native payment protocol built around `402 Payment Required`.

The standard flow is:

1. Client requests a protected resource.
2. Server responds with `402 Payment Required` and payment requirements.
3. Client signs or prepares a payment payload.
4. Client retries the request with the payment payload.
5. Server verifies and settles the payment.
6. Server returns the paid resource or result.

Core actors:

| Actor | Description |
|---|---|
| Resource server | The service requiring payment. In this plugin, Shopware is the resource server. |
| Client | The buyer, API client, wallet client, or autonomous agent. |
| Facilitator | Service that verifies payment payloads and settles payments on-chain. |

Relevant x402 concepts:

| Concept | Shopware interpretation |
|---|---|
| Resource | The payment route for a specific Shopware order transaction. |
| Payment requirements | The exact amount, token, network, merchant wallet, resource URL, timeout, and metadata required to pay an order transaction. |
| Payment payload | The signed client authorization, usually submitted in an `X-PAYMENT` header. |
| Settlement response | Facilitator result containing success/failure, payer, network, and transaction hash. |

### 3.2 x402 `exact` Scheme

The plugin should use the x402 `exact` scheme for MVP because Shopware checkout produces a final, fixed order total before payment.

For EVM-based `exact` payments, x402 uses a signed authorization pattern suitable for gasless token transfer flows such as EIP-3009 `transferWithAuthorization`.

Important payment fields include:

```json
{
  "x402Version": 1,
  "scheme": "exact",
  "network": "base",
  "payload": {
    "signature": "0x...",
    "authorization": {
      "from": "0xPayerWallet",
      "to": "0xMerchantWallet",
      "value": "42990000",
      "validAfter": "...",
      "validBefore": "...",
      "nonce": "0x..."
    }
  }
}
```

### 3.3 x402 Facilitator

The facilitator handles:

- Supported scheme/network discovery.
- Payment payload verification.
- Blockchain settlement.
- Settlement response generation.

Expected facilitator methods:

```text
GET  /supported
POST /verify
POST /settle
```

The plugin should treat the facilitator as a trust boundary and should validate local requirements before and after facilitator calls.

### 3.4 Shopware 6 Payment Extension Model

Shopware payments are applied to **order transactions**.

Key Shopware payment concepts:

| Shopware concept | Meaning for this plugin |
|---|---|
| Payment method | Native Shopware payment method representing x402. |
| Payment handler | Service registered with `shopware.payment.method`. |
| Order transaction | The Shopware entity that should be marked paid after x402 settlement. |
| State machine | Mechanism used to transition transaction states. |
| Store API context | Holds selected payment method in headless checkout. |

Recommended Shopware target:

- Shopware 6.7+.
- Implement `AbstractPaymentHandler`.
- Register payment handler service with `shopware.payment.method`.
- Create payment method on plugin install.
- Deactivate, but do not delete, the payment method on uninstall.

Headless payment integrations should not rely on Storefront sessions. Therefore, this plugin should expose custom Store API routes for x402 payment handling.

### 3.5 SwagAgenticCommerce (UCP Integration)

Shopware's `SwagAgenticCommerce` plugin exposes the Universal Commerce Protocol (UCP) for agentic shopping: catalog, cart, checkout, order, identity, and payment-capability flows via the `ucp-php-sdk`. Facts relevant to this plugin:

| UCP integration fact | Consequence for the x402 plugin |
|---|---|
| Publishes `/.well-known/ucp` and serves REST (`/ucp/*`), A2A, MCP, and embedded transports. | The x402 plugin must not claim these paths. `/store-api/x402/*` does not overlap. |
| Owns tables prefixed `swag_agentic_commerce_*` (UCP config, OAuth, checkout completion). | `swag_x402_payment_session` does not collide. |
| Registers one UCP payment handler, `com.shopware.invoice` (`ShopwareInvoicePaymentHandler`), with `supportsTokenization() === false`. Third-party payment plugins integrate via `Ucp\Sdk\Contract\PaymentHandlerInterface` + the `ucp_sdk.payment_handler` service tag. | An optional x402 UCP handler is possible, but it must not fake tokenization (see 26.4). |
| The UCP checkout completer (`CheckoutCompleter`) places orders through Store API order routes. It does **not** call `/store-api/handle-payment`; the order transaction stays in its initial `open` state. | A UCP-placed order is exactly the "open order transaction" input the x402 order-first flow expects. |
| As of today, neither the SDK nor the plugin consumes `PaymentHandlerInterface::prepareInstrument()` during checkout completion. UCP orders are placed with the guest context's **default payment method** of the sales channel. | The x402 payment route cannot require that the x402 payment method was pre-selected on the order; it needs an after-order payment method switch (see 26.3). |
| UCP checkout provisions a **guest customer** with a server-side generated `sw-context-token` that is stored in UCP session metadata and never handed to the UCP client. The client receives the `orderId` and the order `deepLinkCode` instead. | Binding x402 payment sessions exclusively to the caller's `sw-context-token` would lock out UCP-originated buyers. Ownership proof must also accept `orderId` + `deepLinkCode` (see 26.3). |
| Capabilities in `/.well-known/ucp` are pruned per sales channel; `payment_handlers` is only exposed when a registered handler supports tokenization and the `payment_tokenization` capability is enabled. | The x402 plugin must not force capabilities into the UCP profile. x402 discovery stays on `/store-api/x402/capabilities` and the HTTP 402 handshake. |

---

## 4. Product Scope

### 4.1 MVP Scope

The MVP should include:

- Native Shopware payment method: `swag_x402_agentic`.
- Sales-channel-aware plugin configuration.
- Store API capabilities endpoint.
- Store API order payment endpoint returning HTTP `402`.
- x402 payment session persistence.
- x402 payment requirements generation.
- `X-PAYMENT` header parsing.
- Local payload/session validation.
- Facilitator `/verify` and `/settle` integration.
- Shopware transaction state transitions.
- Idempotency and replay protection.
- Scheduled expiry of stale sessions.
- Support/admin visibility into payment session state.
- Order ownership proof via context token **or** order deep link code, so orders placed through UCP checkout (`SwagAgenticCommerce`) can be paid (section 26).
- Optional after-order payment method switch to x402 for orders placed with a different payment method (section 26.3).

### 4.2 Later Scope

Potential later features:

- Storefront fallback wallet page.
- One-call agentic purchase API.
- Refund support.
- Multi-token support.
- Dynamic FX conversion.
- Webhook support from facilitators.
- Self-hosted facilitator mode.
- Administration order-detail module.
- App Store / Store listing packaging.
- UCP payment handler bridge (`ucp_sdk.payment_handler`) advertising x402 through the UCP profile, once the UCP SDK wires payment instrument preparation into checkout completion (section 26.4).

---

## 5. Architecture

### 5.1 High-Level Architecture

```text
Shopware Store API
  |
  | cart, context, order creation
  v
Shopware Order + Open Order Transaction
  |
  | custom x402 Store API route
  v
X402 Payment Session
  |
  | payment requirements
  v
Agent / x402 Client / Wallet
  |
  | signed payment payload
  v
X402 Payment Route
  |
  | verify + settle
  v
X402 Facilitator
  |
  | settlement result
  v
Shopware Transaction State Machine
  |
  v
order_transaction.state = paid
```

### 5.2 Package Structure

```text
custom/plugins/SwagX402Payments/
  composer.json
  src/
    SwagX402Payments.php

    Core/
      Checkout/
        Payment/
          X402PaymentHandler.php
          X402PaymentMethodInstaller.php
          X402TransactionStateService.php

      X402/
        X402RequirementBuilder.php
        X402PaymentSessionService.php
        X402PayloadParser.php
        X402PayloadValidator.php
        X402FacilitatorClient.php
        X402SettlementService.php
        X402IdempotencyService.php
        X402AmountConverter.php

      Content/
        X402PaymentSession/
          X402PaymentSessionDefinition.php
          X402PaymentSessionEntity.php
          X402PaymentSessionCollection.php

    StoreApi/
      X402CapabilitiesRoute.php
      X402OrderPaymentRoute.php
      X402PaymentSessionRoute.php

    Migration/
      MigrationXXXXXXXXXXCreateX402PaymentSession.php

    ScheduledTask/
      ExpireX402PaymentSessionsTask.php
      ExpireX402PaymentSessionsHandler.php

    Resources/
      config/
        services.xml
        routes.xml
      config.xml
      snippet/
```

---

## 6. Payment Method Specification

### 6.1 Payment Method Metadata

```text
technicalName: swag_x402_agentic
name: x402 Agentic Payment
handlerIdentifier: Swag\X402Payments\Core\Checkout\Payment\X402PaymentHandler
afterOrderEnabled: true
active: false by default until configured
```

### 6.2 Payment Handler Responsibility

The `X402PaymentHandler` is required so Shopware recognizes the plugin as a payment extension.

For the headless MVP, the handler should be intentionally minimal. The canonical payment flow should happen through custom Store API routes, not a redirect page.

Possible handler behavior:

```php
final class X402PaymentHandler extends AbstractPaymentHandler
{
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): ?RedirectResponse {
        // Optional storefront fallback only.
        // For headless mode, return null or provide a redirect to a simple payment info page.
    }

    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        // Optional storefront fallback only.
        // Headless route should mark transactions paid directly after settlement.
    }
}
```

---

## 7. Store API Specification

### 7.1 Capabilities Endpoint

```http
GET /store-api/x402/capabilities
sw-context-token: <context-token>
```

Purpose:

- Let agents discover whether the shop supports x402.
- Return supported x402 schemes, networks, assets, and limits.

Example response:

```json
{
  "x402Version": 1,
  "schemes": ["exact"],
  "networks": [
    {
      "network": "base",
      "asset": "0xUSDC",
      "symbol": "USDC",
      "decimals": 6
    }
  ],
  "limits": {
    "minAmount": "0.50",
    "maxAmount": "1000.00"
  },
  "paymentMethodTechnicalName": "swag_x402_agentic"
}
```

---

### 7.2 Order Payment Endpoint

```http
POST /store-api/x402/order/{orderId}/pay
sw-context-token: <context-token>
Idempotency-Key: <agent-generated-key>
```

This is the canonical headless x402 endpoint.

#### 7.2.0 Order Ownership Proof

The route must accept exactly one of two ownership proofs for the order:

```text
Proof A: the sw-context-token belongs to the customer (or guest context) that placed the order.
Proof B: the request provides the order deepLinkCode, e.g. ?deepLinkCode=... or JSON body field.
```

Proof B exists because orders placed through UCP checkout (`SwagAgenticCommerce`) are created under a plugin-internal guest context token that the agent never receives; UCP hands the client the `orderId` and `deepLinkCode` instead (section 26). `deepLinkCode` is Shopware's standard high-entropy guest-order credential, so Proof B grants no more access than Shopware's own guest order routes.

Requests with neither proof, or with a `deepLinkCode` that does not match the order, must be rejected with `403` before any payment session is created or disclosed.

#### 7.2.1 Call Without Payment Header

If the request does not include `X-PAYMENT`, return HTTP `402`.

```http
HTTP/1.1 402 Payment Required
Content-Type: application/json
```

Example response:

```json
{
  "x402Version": 1,
  "error": "X-PAYMENT header is required",
  "accepts": [
    {
      "scheme": "exact",
      "network": "base",
      "maxAmountRequired": "42990000",
      "asset": "0xUSDC",
      "payTo": "0xMerchantWallet",
      "resource": "https://shop.example.com/store-api/x402/order/018f.../pay",
      "description": "Shopware order 10042",
      "mimeType": "application/json",
      "outputSchema": null,
      "maxTimeoutSeconds": 300,
      "extra": {
        "name": "USDC",
        "version": "2",
        "shopwareOrderTransactionId": "018f...",
        "paymentSessionId": "018f..."
      }
    }
  ],
  "shopware": {
    "orderId": "018f...",
    "orderNumber": "10042",
    "orderTransactionId": "018f...",
    "paymentSessionId": "018f...",
    "amount": {
      "currency": "EUR",
      "totalPrice": 42.99
    }
  }
}
```

#### 7.2.2 Call With Payment Header

```http
POST /store-api/x402/order/{orderId}/pay
sw-context-token: <context-token>
Idempotency-Key: <agent-generated-key>
X-PAYMENT: <base64-or-encoded-payment-payload>
```

Processing steps:

```text
parse X-PAYMENT payload
→ lock payment session
→ check idempotency
→ validate payload against persisted requirements
→ call facilitator /verify
→ call facilitator /settle
→ persist settlement response
→ transition Shopware transaction to paid
→ return 200 with payment status
```

Success response:

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-PAYMENT-RESPONSE: <encoded-settlement-response>
```

```json
{
  "status": "paid",
  "orderId": "018f...",
  "orderNumber": "10042",
  "orderTransactionId": "018f...",
  "transactionState": "paid",
  "payment": {
    "paymentSessionId": "018f...",
    "scheme": "exact",
    "network": "base",
    "asset": "USDC",
    "amountAtomic": "42990000",
    "payer": "0xAgentWallet",
    "transactionHash": "0x..."
  }
}
```

---

### 7.3 Payment Session Status Endpoint

```http
GET /store-api/x402/payment-session/{paymentSessionId}
sw-context-token: <context-token>
```

Example response:

```json
{
  "paymentSessionId": "018f...",
  "status": "settled",
  "orderId": "018f...",
  "orderTransactionId": "018f...",
  "transactionState": "paid",
  "network": "base",
  "transactionHash": "0x..."
}
```

Purpose:

- Allow polling.
- Support delayed facilitator settlement.
- Help agents recover from network failures.

---

## 8. Headless Checkout Flow

### 8.1 Normal Store API Checkout

1. Agent creates or receives a Store API context token.
2. Agent adds line items to cart.
3. Agent sets shipping, billing, and payment method context.
4. Agent selects `swag_x402_agentic` as payment method.
5. Agent places order through Store API.
6. Shopware creates order with open transaction.
7. Agent calls `/store-api/x402/order/{orderId}/pay`.
8. Plugin returns HTTP `402` with x402 requirements.
9. Agent signs payment requirements.
10. Agent retries same route with `X-PAYMENT`.
11. Plugin verifies and settles payment.
12. Plugin marks transaction paid.
13. Agent receives paid order status.

### 8.2 Recommended MVP Flow

Use an **order-first** flow:

```text
create Shopware order first
→ bind x402 requirement to real order transaction
→ pay that transaction
```

Do not settle payment before creating an order in MVP. Paying before order creation requires quote reservation, stock locking, tax consistency, and failure recovery logic.

### 8.3 UCP-Originated Checkout (SwagAgenticCommerce Installed)

When the order is created by a UCP checkout instead of a direct Store API checkout, the same order-first flow applies from step 7 onward:

1. Agent runs a UCP checkout (REST/A2A/MCP) against `SwagAgenticCommerce`.
2. UCP completes the checkout: it provisions a guest customer, places the order through Store API order routes, and leaves the order transaction `open`. The order is placed with the sales channel's default payment method, because UCP does not currently select payment methods per checkout.
3. Agent receives `orderId` and `deepLinkCode` from the completed UCP checkout.
4. Agent calls `POST /store-api/x402/order/{orderId}/pay` with `deepLinkCode` as ownership proof (section 7.2.0).
5. If the order transaction's payment method is not x402 and the after-order switch is enabled (section 26.3), the plugin switches the transaction to the x402 payment method before issuing requirements.
6. Flow continues identically: HTTP 402 → sign → `X-PAYMENT` → verify/settle → transaction `paid`.

The x402 plugin must not require any UCP API, table, or service for this flow; it only consumes standard Shopware order data. Section 26 defines the full compatibility contract.

---

## 9. Data Model

### 9.1 Table: `swag_x402_payment_session`

| Field | Type | Purpose |
|---|---|---|
| `id` | binary(16) | Payment session ID. |
| `sales_channel_id` | binary(16) | Sales channel scope. |
| `context_token_hash` | varchar nullable | Bind session to Store API context without storing raw token. Nullable: sessions created via deep-link ownership proof (e.g. UCP-placed orders, section 7.2.0) have no caller context token. |
| `ownership_proof` | varchar | Which proof created the session: `context_token` or `deep_link_code`. |
| `deep_link_code_hash` | char(64) nullable | Hash of the order deepLinkCode when ownership proof B was used; never store the raw code. |
| `order_id` | binary(16) | Shopware order ID. |
| `order_transaction_id` | binary(16) | Shopware transaction being paid. |
| `order_number` | varchar | Human-readable order reference. |
| `state` | varchar | Session state. |
| `scheme` | varchar | `exact` for MVP. |
| `network` | varchar | x402 network, e.g. `base`. |
| `asset` | varchar | Token contract address. |
| `asset_symbol` | varchar | Token symbol, e.g. USDC. |
| `asset_decimals` | int | Token decimals. |
| `shopware_currency` | varchar | Original Shopware currency. |
| `shopware_amount` | decimal | Original order transaction amount. |
| `payment_amount_atomic` | varchar | Token amount in atomic units. |
| `pay_to` | varchar | Merchant recipient wallet. |
| `resource_url` | varchar | Exact x402 resource URL. |
| `quote_hash` | char(64) | Hash of bound quote/payment data. |
| `requirements_json` | json | Persisted x402 payment requirements. |
| `payment_payload_hash` | char(64), nullable unique | Replay protection. |
| `payer_wallet` | varchar nullable | Payer from verify/settle response. |
| `verify_response_json` | json nullable | Facilitator verify response. |
| `settle_response_json` | json nullable | Facilitator settle response. |
| `settlement_transaction_hash` | varchar nullable | On-chain settlement transaction. |
| `idempotency_key_hash` | char(64) | Retry safety. |
| `expires_at` | datetime | Session expiry. |
| `paid_at` | datetime nullable | Settlement timestamp. |
| `failure_reason` | text nullable | Support/debug field. |
| `created_at` | datetime | Created timestamp. |
| `updated_at` | datetime | Updated timestamp. |

### 9.2 Required Indexes

```sql
UNIQUE(order_transaction_id)
UNIQUE(payment_payload_hash)
UNIQUE(order_transaction_id, idempotency_key_hash)
INDEX(state, expires_at)
INDEX(settlement_transaction_hash)
```

---

## 10. State Mapping

| x402 session state | Shopware transaction state |
|---|---|
| `created` | `open` |
| `requirements_issued` | `open` |
| `payload_received` | `in_progress` |
| `verified` | `in_progress` |
| `settled` | `paid` |
| `expired` | `cancelled` |
| `verify_failed` | `failed` |
| `settlement_failed` | `failed` or `in_progress`, depending on recoverability |
| `duplicate_retry_after_settlement` | Keep `paid`, return previous result |

Rule:

> Only mark the Shopware transaction as `paid` after successful settlement, not after signature verification.

---

## 11. Requirement Binding

Each x402 payment requirement must be bound to the exact Shopware transaction.

Include the following in the persisted quote hash and/or payment requirements metadata:

```text
salesChannelId
ownership proof (hashed sw-context-token or hashed deepLinkCode)
orderId
orderTransactionId
orderNumber
Shopware amount
Shopware currency
payment asset
payment amount in atomic units
network
merchant payTo wallet
resource URL
maxTimeoutSeconds / expiresAt
idempotency key hash
quote hash
```

The x402 `resource` should be:

```text
https://shop.example.com/store-api/x402/order/{orderId}/pay
```

Do not include personal data in x402 metadata.

Avoid including:

```text
customer name
customer email
billing address
shipping address
cart contents
product names
internal customer IDs
```

Prefer metadata such as:

```text
Shopware order 10042
paymentSessionId
orderTransactionId
```

---

## 12. Service Specifications

### 12.1 `X402RequirementBuilder`

Responsibilities:

- Load order transaction amount from Shopware.
- Validate x402 payment method is selected on the order transaction; if not, apply the after-order switch when enabled (section 26.3) or reject.
- Convert amount to token atomic units.
- Build resource URL.
- Build x402 `accepts[]` requirements.
- Generate quote hash.
- Persist requirements JSON.

Inputs:

```text
OrderEntity
OrderTransactionEntity
SalesChannelContext
Plugin config
Idempotency key
```

Outputs:

```text
PaymentRequirementsResponse DTO
PaymentSessionEntity
quoteHash
```

---

### 12.2 `X402PayloadParser`

Responsibilities:

- Read `X-PAYMENT` header.
- Decode payload according to pinned x402 SDK/spec version.
- Validate JSON structure.
- Normalize payload into internal DTO.
- Calculate raw payload hash.

---

### 12.3 `X402PayloadValidator`

Local checks before facilitator call:

```text
payload.x402Version == 1
payload.scheme == session.scheme
payload.network == session.network
payload.authorization.to == session.pay_to
payload.authorization.value >= session.payment_amount_atomic
payload.authorization.validBefore >= current timestamp
session is not expired
payload hash was not previously used
order transaction is still open or in_progress
```

The facilitator still performs cryptographic verification.

---

### 12.4 `X402FacilitatorClient`

Methods:

```php
supported(): SupportedKindsResponse;
verify(PaymentPayload $payload, PaymentRequirements $requirements): VerifyResponse;
settle(PaymentPayload $payload, PaymentRequirements $requirements): SettlementResponse;
```

Requirements:

- Configurable base URL.
- Optional API key support.
- Request/response timeout.
- Structured error handling.
- JSON schema validation.
- Redacted logging.

---

### 12.5 `X402SettlementService`

Responsibilities:

```text
open DB transaction
SELECT payment_session FOR UPDATE
handle duplicate idempotent retries
run local validation
transition Shopware transaction to in_progress if needed
call facilitator /verify
call facilitator /settle
persist responses
transition Shopware transaction to paid
commit transaction
```

Idempotent behavior:

- If the session is already settled, return stored settlement result.
- If the same idempotency key is retried, return the prior result where possible.
- If the same payload hash appears for a different order, reject it.

---

### 12.6 `X402TransactionStateService`

Wraps Shopware state transitions:

```text
open → in_progress
in_progress → paid
open/in_progress → failed
open/in_progress → cancelled
```

Controllers must not directly mutate transaction state.

---

## 13. Merchant Configuration

Sales-channel-aware configuration fields:

| Setting | Required | Notes |
|---|---:|---|
| Enabled | Yes | Controls availability. |
| Testnet/mainnet mode | Yes | Determines network and facilitator config. |
| Facilitator base URL | Yes | Endpoint for `/supported`, `/verify`, `/settle`. |
| Facilitator API key | Optional | Depends on provider. |
| Merchant payTo wallet | Yes | Recipient wallet. |
| Supported network | Yes | MVP: one network. |
| Supported asset contract | Yes | Token contract. |
| Asset symbol | Yes | Example: USDC. |
| Asset decimals | Yes | Used for atomic conversion. |
| Minimum order amount | Yes | Reject too-small orders. |
| Maximum order amount | Yes | Risk control. |
| maxTimeoutSeconds | Yes | x402 payment requirement timeout. |
| Settlement timeout | Yes | Facilitator HTTP timeout. |
| Expire unpaid sessions after N minutes | Yes | Cleanup and recovery. |
| Debug logging | Optional | Must redact sensitive values. |
| Metadata privacy mode | Yes | Avoid PII in payment metadata. |
| Allow deep-link ownership proof | Yes | Default on. Required for paying UCP-placed orders (section 7.2.0). |
| Allow after-order payment method switch | Yes | Default on. Lets the pay route switch an `open` transaction from another payment method to x402 (section 26.3). Required for UCP-placed orders. |

### 13.1 FX Strategy

For MVP, avoid dynamic FX when possible.

Recommended MVP options:

```text
Option A: Only allow x402 when Shopware currency matches stablecoin denomination.
Option B: Use a locked quote provider and persist quote rate, timestamp, source, and expiry.
```

If FX is implemented, persist:

```text
fx source
fx rate
fx timestamp
quote expiry
original fiat amount
atomic token amount
```

---

## 14. Error Handling

| Case | HTTP | Shopware state | Response behavior |
|---|---:|---|---|
| Missing `X-PAYMENT` header | 402 | `open` | Return x402 requirements. |
| No valid ownership proof (section 7.2.0) | 403 | unchanged | Structured error; do not disclose order or session data. |
| Order transaction uses another payment method, switch disabled | 409 | unchanged | Structured error `SWAG_X402__PAYMENT_METHOD_NOT_SELECTED`. |
| Unsupported network | 400 | unchanged | Structured error. |
| Expired quote | 402 | `open` or `cancelled` | Return new requirements if possible. |
| Invalid signature | 402 or 400 | maybe `failed` after threshold | Return verification error. |
| Insufficient funds | 402 | `open` | Allow retry. |
| Settlement failed | 502 or 409 | `failed` or `in_progress` | Depends on recoverability. |
| Duplicate successful retry | 200 | `paid` | Return previous settlement result. |
| Already paid order | 200 | `paid` | Return paid status. |

Example structured error:

```json
{
  "errors": [
    {
      "code": "SWAG_X402__QUOTE_EXPIRED",
      "detail": "The payment requirements expired. Request new requirements.",
      "meta": {
        "paymentSessionId": "018f..."
      }
    }
  ]
}
```

---

## 15. Security Requirements

Minimum security controls:

```text
Use HTTPS-only resource URLs.
Bind payment to orderTransactionId and resource URL.
Do not trust client-submitted amount.
Persist and uniqueness-check payment payload hash.
Lock session row during verify/settle.
Make settlement idempotent.
Reject reused payloads across orders.
Keep x402 metadata minimal and non-PII.
Expire requirements quickly.
Never mark paid before settlement.
Log hashes/statuses, not raw signatures/API keys.
Validate facilitator response schemas.
Use pessimistic locking for concurrent retries.
```

### 15.1 Replay Protection

Persist:

```text
payment_payload_hash
authorization.nonce
orderTransactionId
quoteHash
```

Reject:

```text
same payload for different order
same nonce for different order
payload with mismatched payTo
payload with mismatched amount
payload with mismatched network
payload after session expiry
```

### 15.2 Concurrency Control

Pseudo-flow:

```text
BEGIN TRANSACTION
SELECT x402_payment_session FOR UPDATE

if state == settled:
    return previous result

if payment_payload_hash exists elsewhere:
    reject replay

validate payload locally
call facilitator /verify
call facilitator /settle
persist settlement response
transition Shopware transaction to paid

COMMIT
```

### 15.3 Privacy

Do not send customer PII to the facilitator through x402 requirement metadata.

Use:

```text
Shopware order number
paymentSessionId
orderTransactionId
opaque resource URL
```

Avoid:

```text
customer name
email
address
product names
cart details
```

---

## 16. Observability and Admin Support

### 16.1 Logs

Log:

```text
paymentSessionId
orderTransactionId
quoteHash
payloadHash
facilitator status
settlement transaction hash
state transitions
error code
```

Do not log:

```text
raw signatures
API keys
full payment payloads
customer PII
raw sw-context-token
```

### 16.2 Admin View

Admin/support should be able to inspect:

```text
payment session ID
Shopware order number
transaction state
x402 session state
network
asset
amount
payer wallet
merchant wallet
settlement transaction hash
facilitator verify/settle status
failure reason
created/updated/paid timestamps
```

For MVP this can be logs plus DAL visibility. A custom administration module can come later.

---

## 17. Scheduled Tasks and Recovery

### 17.1 Expiry Task

A scheduled task should expire stale sessions:

```text
find sessions where state in created, requirements_issued, payload_received, verified
and expires_at < now
→ mark session expired
→ optionally transition transaction to cancelled or failed depending on config
```

### 17.2 Recovery Command

Implement a CLI/admin recovery path for cases where settlement succeeded but Shopware state transition failed.

Recovery should:

```text
load session by transaction hash or paymentSessionId
verify settle_response_json.success == true
verify order transaction is not paid
transition transaction to paid
record recovery audit entry
```

---

## 18. Agent SDK Example

Target developer experience:

```ts
await client.addLineItem(productId, 1);
await client.setPaymentMethod("swag_x402_agentic");
const order = await client.createOrder();

const first = await client.post(`/store-api/x402/order/${order.id}/pay`, {
  headers: {
    "Idempotency-Key": idempotencyKey,
  },
});

if (first.status === 402) {
  const paymentPayload = await x402Signer.sign(first.body.accepts[0]);

  const paid = await client.post(`/store-api/x402/order/${order.id}/pay`, {
    headers: {
      "X-PAYMENT": paymentPayload,
      "Idempotency-Key": idempotencyKey,
    },
  });
}
```

Variant for orders placed through UCP checkout (`SwagAgenticCommerce`), where the agent holds no Shopware context token and proves ownership with the deep link code (section 7.2.0):

```ts
const checkout = await ucpClient.completeCheckout(checkoutId); // returns orderId; deepLinkCode from checkout session

const first = await fetch(
  `${shopUrl}/store-api/x402/order/${checkout.orderId}/pay?deepLinkCode=${deepLinkCode}`,
  { method: "POST", headers: { "Idempotency-Key": idempotencyKey } },
);
// ...same 402 → sign → X-PAYMENT retry as above
```

---

## 19. Testing Plan

### 19.1 Unit Tests

```text
amount conversion
quote hash generation
payment requirements generation
payload parsing
payload validation
config validation
state mapping
```

### 19.2 Integration Tests

```text
payment method installation
Store API capabilities route
Store API 402 payment route
payment session persistence
facilitator mock verify/settle
Shopware transaction state transitions
```

### 19.3 Security Tests

```text
replay same payload across orders
altered amount
altered payTo
altered network
expired quote
duplicate concurrent requests
settle success but state transition failure
invalid facilitator response
PII leakage in requirements JSON
```

### 19.4 Acceptance Tests

```text
Agent can place order with x402 payment method.
Agent receives HTTP 402 payment requirements.
Agent signs payment requirements.
Agent retries with X-PAYMENT.
Plugin verifies and settles through facilitator.
Shopware transaction becomes paid.
Duplicate retry returns the already-paid result.
Cross-order replay is rejected.
Expired payment requirements cannot be paid.
An order placed through UCP checkout (SwagAgenticCommerce installed) can be paid with x402 using orderId + deepLinkCode (section 26.5).
Installing the x402 plugin does not change /.well-known/ucp or any UCP endpoint behavior.
```

---

## 20. Implementation Plan

### Phase 0: Decisions and Spike

Deliverables:

```text
Choose Shopware target version: 6.7+
Choose x402 spec/SDK version
Choose facilitator provider or self-hosting option
Choose first network and asset
Decide FX strategy
Define legal/compliance assumptions
```

Outputs:

```text
Architecture decision record
API contract draft
Threat model draft
```

---

### Phase 1: Plugin Foundation

Build:

```text
plugin skeleton
composer metadata
payment handler class
service registration with shopware.payment.method
payment method installer
plugin config
basic health check
```

Acceptance criteria:

```text
Plugin installs.
Payment method exists.
Payment method is inactive until configured.
Payment method is deactivated, not deleted, on uninstall.
Admin can configure facilitator, network, token, and wallet.
```

---

### Phase 2: Payment Session Entity

Build:

```text
swag_x402_payment_session entity
DAL definition/entity/collection
migration
repository service
session creation and lookup
quote hash generation
expiry handling
```

Acceptance criteria:

```text
A Shopware order transaction can be linked to exactly one active x402 payment session.
The same active quote is reused for idempotent retries.
Expired sessions are not payable.
```

---

### Phase 3: Store API Capabilities and Requirements

Build:

```text
GET /store-api/x402/capabilities
POST /store-api/x402/order/{orderId}/pay without X-PAYMENT
X402RequirementBuilder
amount-to-atomic conversion
resource URL generation
```

Acceptance criteria:

```text
Agent can discover x402 support.
Agent can place an order and receive HTTP 402 requirements.
Requirements are generated from persisted Shopware order transaction data only.
```

---

### Phase 4: Payment Payload Handling

Build:

```text
X-PAYMENT header parser
payload schema validation
payload hash calculation
local order/session binding checks
duplicate payload rejection
idempotency-key handling
```

Acceptance criteria:

```text
Malformed payload returns structured error.
Wrong order amount/payTo/network is rejected.
Same payload cannot be used for a different order.
Duplicate retry for the same settled order returns previous result.
```

---

### Phase 5: Facilitator Integration

Build:

```text
X402FacilitatorClient
/supported compatibility check
/verify call
/settle call
timeout and retry policy
response persistence
```

Acceptance criteria:

```text
Valid payment payload is verified.
Settlement transaction hash is stored.
Facilitator failure does not mark order paid.
```

---

### Phase 6: Shopware State Transitions

Build:

```text
X402TransactionStateService
open → in_progress
in_progress → paid
open/in_progress → failed
open/in_progress → cancelled
```

Acceptance criteria:

```text
Transaction becomes paid only after settlement success.
Finalize/retry calls are idempotent.
State transition failure can be recovered without double settlement.
```

---

### Phase 7: Scheduled Cleanup and Recovery

Build:

```text
scheduled task to expire stale sessions
recovery command for settled-but-not-paid sessions
admin-visible status fields
logs and metrics
```

Acceptance criteria:

```text
Expired unpaid sessions are cancelled or failed according to config.
If settlement succeeded but Shopware state transition failed, recovery can mark the transaction paid based on stored settlement evidence.
```

---

### Phase 8: Agent SDK and Examples

Build:

```text
TypeScript client example
curl examples
MCP/tool-use example
OpenAPI snippets
testnet demo
```

Acceptance criteria:

```text
A non-browser agent can complete checkout and payment.
No storefront redirect or session state is required.
```

---

### Phase 9: Hardening and Release

Build tests for:

```text
replay across orders
altered amount
altered payTo
altered network
expired quote
parallel retry
facilitator timeout
settled-but-Shopware-transition-failed recovery
```

Acceptance criteria:

```text
No double payment.
No unpaid order is marked paid.
No paid order remains permanently open without a recovery path.
No customer PII is sent to x402 facilitator metadata.
```

---

## 21. MVP Definition of Done

The MVP is complete when:

```text
Shopware 6.7 plugin installs and creates x402 payment method.
Agent can use Store API to build cart and place order.
Agent can select x402 payment method.
Agent can call /store-api/x402/order/{orderId}/pay and receive HTTP 402.
Agent can sign x402 exact payment requirements.
Plugin verifies and settles through facilitator.
Shopware order transaction becomes paid after settlement.
Duplicate retries are idempotent.
Replay across orders is rejected.
Expired requirements cannot be paid.
Support can inspect payment session and settlement hash.
Dual-install with SwagAgenticCommerce passes: UCP untouched, UCP-placed order payable via deepLinkCode + after-order switch (section 26).
Plugin installs and runs without SwagAgenticCommerce / ucp-php-sdk present.
```

---

## 22. Open Questions

| Area | Question |
|---|---|
| Facilitator | Which facilitator provider should be used for MVP? |
| Network | Which network should be supported first: Base, Base Sepolia, Polygon, or another? |
| Asset | USDC, EURC, or both? |
| Currency | Should Shopware EUR orders map to EURC or be converted to USDC? |
| FX | Is locked FX required for MVP? |
| Compliance | Which countries/sales channels should expose x402? |
| Refunds | Should refunds be manual-only in MVP? |
| Storefront | Is a browser wallet fallback required for non-agent users? |
| SDK | Should the plugin ship a TypeScript agent SDK or only OpenAPI examples? |
| UCP | See section 26.6 for UCP/SwagAgenticCommerce-specific open questions. |

---

## 23. Source Notes

This specification is based on the researched concepts from:

- Shopware 6 payment concepts and payment plugin architecture.
- Shopware Store API headless checkout behavior.
- x402 protocol v1 architecture and payment flow.
- x402 `exact` scheme behavior.
- x402 facilitator verification and settlement model.
- Security considerations for replay protection, idempotency, and order/payment binding.


---

## 24. Testability and Verification Specification

### 24.1 Verification Goal

The project is considered verifiable when automated and manual evidence proves all of the following:

```text
A Shopware order transaction cannot become paid unless x402 settlement succeeded.
A valid settled x402 payment cannot remain unrecoverably disconnected from its Shopware order transaction.
The same x402 payment payload cannot pay multiple Shopware orders.
The same Shopware order transaction cannot be settled twice.
The x402 quote is bound to the exact Shopware order transaction, amount, token, network, payTo address, resource URL, and expiry.
The Store API flow works without Storefront session state or browser redirects.
```

The test strategy should therefore verify both sides of the bridge:

| Layer | What must be proven |
|---|---|
| Shopware side | Plugin install, payment method registration, Store API route behavior, DAL persistence, transaction state transitions. |
| x402 side | Correct `402` response, correct `X-PAYMENT` parsing, correct facilitator `/verify` and `/settle` calls, correct `X-PAYMENT-RESPONSE`. |
| Bridge logic | Quote binding, idempotency, replay prevention, concurrency locking, recovery after partial failure. |

### 24.2 Research Findings Relevant to Testing

Shopware itself is testable through PHPUnit-based unit and integration suites. The Shopware repository uses `phpunit.xml.dist` with a test bootstrap, `APP_ENV=test`, strict risky-test handling, and separate `tests/unit` and `tests/integration` suites. This is the model the plugin should follow.

Shopware Store API integration tests can be written with a Symfony `KernelBrowser` using Shopware test traits. Existing Shopware tests create a custom sales-channel browser, set the `HTTP_SW_CONTEXT_TOKEN` header, and call Store API endpoints such as `/store-api/checkout/order`. The x402 plugin should test its custom Store API routes the same way.

x402 HTTP transport is directly testable because the protocol has explicit observable HTTP behavior:

```text
No payment payload → HTTP 402 with PaymentRequirementsResponse JSON.
Payment payload submitted in X-PAYMENT → base64-encoded PaymentPayload JSON.
Successful settlement → HTTP 200 with X-PAYMENT-RESPONSE.
Malformed payment → HTTP 400 or HTTP 402 depending on failure class.
```

The x402 facilitator interface is also easy to mock because it has three clear calls:

```text
GET /supported
POST /verify
POST /settle
```

The most important security research findings to convert into tests are:

```text
Replay prevention must be tested.
Cross-resource / cross-order substitution must be tested.
HTTP-layer and blockchain-settlement state synchronization must be tested.
Concurrent duplicate-service or duplicate-settlement attempts must be tested.
Paid-but-denied and unpaid-but-fulfilled outcomes must be tested.
Metadata privacy must be tested.
```

### 24.3 Test Architecture

Use four test levels.

#### Level 1: Pure Unit Tests

Purpose: deterministic tests without a Shopware kernel, database, HTTP server, facilitator, or chain.

Target classes:

```text
X402AmountConverter
X402RequirementBuilder
X402PayloadParser
X402PayloadValidator
X402IdempotencyService
X402QuoteHasher
X402MetadataSanitizer
X402ConfigValidator
```

Examples:

| Test | Expected result |
|---|---|
| EUR 42.99 to USDC 6 decimals | `42990000` atomic units. |
| Unsupported currency | Throws config/domain exception. |
| Missing `X-PAYMENT` header | Parser returns `null`, controller must produce `402`. |
| Invalid base64 `X-PAYMENT` | Structured `SWAG_X402__INVALID_PAYMENT_HEADER` error. |
| Payload `authorization.to` differs from `payTo` | Validation fails before facilitator call. |
| Payload amount below `maxAmountRequired` | Validation fails. |
| Payload network differs from quote | Validation fails. |
| `validBefore` is in the past | Validation fails. |
| Metadata contains email/address-like PII | Sanitizer redacts or rejects, depending config. |

Minimum unit coverage target:

```text
95%+ branch coverage for amount conversion, quote hashing, payload validation, and idempotency decisions.
```

#### Level 2: Shopware Integration Tests

Purpose: verify the plugin in a real Shopware kernel with DAL repositories, routing, service container, and state machine.

Target tests:

```text
Plugin installation creates payment method.
Payment method is active only when config is valid.
Uninstall deactivates but does not delete payment method.
Custom Store API routes are registered.
Capabilities endpoint returns configured schemes/networks/assets.
Order-first payment route loads correct order transaction.
Payment session entity persists requirements JSON and quote hash.
Transaction transitions only through allowed Shopware state-machine paths.
Scheduled task expires stale sessions.
```

Use `KernelBrowser`/Store API integration tests to exercise:

```text
POST /store-api/x402/order/{orderId}/pay without X-PAYMENT
POST /store-api/x402/order/{orderId}/pay with X-PAYMENT
GET  /store-api/x402/payment-session/{sessionId}
GET  /store-api/x402/capabilities
```

Assertions for the `402` response:

```text
HTTP status is 402.
Body contains x402Version = 1.
Body contains accepts[0].scheme = exact.
accepts[0].maxAmountRequired equals persisted order transaction amount converted to atomic units.
accepts[0].payTo equals configured merchant wallet.
accepts[0].resource equals the exact Store API payment route URL.
requirements_json in DB equals the response body requirements.
No customer email, name, address, or line-item names are present in the x402 metadata.
```

Assertions for successful payment:

```text
Facilitator /verify called once.
Facilitator /settle called once.
Payment session state is settled.
Payment payload hash is persisted.
Settlement transaction hash is persisted.
Shopware order transaction state is paid.
Response status is 200.
Response includes X-PAYMENT-RESPONSE.
```

#### Level 3: Contract Tests Against a Mock Facilitator

Purpose: verify that the plugin sends and handles facilitator requests exactly as expected without relying on real chain infrastructure.

Implement a local mock facilitator service with programmable scenarios:

```text
/supported returns supported exact/base and exact/base-sepolia.
/verify returns isValid=true.
/verify returns isValid=false with insufficient_funds.
/verify times out.
/verify returns malformed JSON.
/settle returns success=true with transaction hash.
/settle returns success=false with errorReason.
/settle times out after verify succeeded.
/settle returns duplicate/already-used nonce.
```

Contract assertions:

```text
/verify request contains x402Version, paymentPayload, and the exact persisted paymentRequirements.
/settle request is identical to /verify request for the same session.
No client-submitted amount is forwarded unless it matches persisted requirements.
Facilitator API key is sent only as configured and never logged.
Timeouts do not mark the order paid.
Malformed facilitator responses are treated as failed external dependency responses.
```

#### Level 4: End-to-End Agent Tests

Purpose: prove a real non-browser agent can complete the full Store API checkout and x402 payment route flow.

MVP E2E can run against:

```text
Shopware test app/container
mock facilitator
fixture wallet signer or deterministic fake x402 payload accepted by mock facilitator
fixture product/customer/sales channel
```

Optional nightly E2E can run against:

```text
Base Sepolia or selected testnet
real facilitator sandbox/testnet endpoint
test wallet funded with test USDC
```

E2E scenarios:

| Scenario | Expected result |
|---|---|
| Happy path checkout | Order transaction becomes paid. |
| Missing payment header | Agent receives `402` and requirements. |
| Expired requirements | Agent receives new `402` or structured expiry error; no settlement. |
| Wrong network | Payment rejected before settlement. |
| Wrong `payTo` | Payment rejected before settlement. |
| Duplicate retry after success | Returns previous paid response; no second settlement call. |
| Parallel retry | Exactly one settlement succeeds; all callers receive consistent final state. |
| Facilitator outage | Transaction remains open/in_progress or failed according to policy; never paid. |

### 24.4 Security and Abuse Test Cases

These tests are mandatory because x402 bridges synchronous HTTP with asynchronous blockchain settlement.

#### Replay Across Orders

```text
1. Create order A and receive requirements A.
2. Submit valid payment payload for A and settle.
3. Create order B with same amount.
4. Submit payload A to order B endpoint.
Expected: B rejects payload; B remains unpaid; no facilitator settle call for B.
```

#### Cross-Resource Substitution

```text
1. Create requirements for /store-api/x402/order/A/pay.
2. Submit the signed payload to /store-api/x402/order/B/pay.
Expected: local validation rejects because resource/orderTransactionId/quoteHash do not match.
```

#### Amount Tampering

```text
1. Create order for 42.99.
2. Submit payload for 4.29 or altered requirements.
Expected: local validation rejects before /verify.
```

#### Merchant Wallet Tampering

```text
1. Create valid requirements with merchant payTo wallet.
2. Submit payload signed for attacker wallet.
Expected: local validation rejects before /verify.
```

#### Concurrency Double-Settlement

```text
1. Create one order/session.
2. Send N parallel requests with the same valid X-PAYMENT payload.
Expected: exactly one DB lock owner calls /settle; all other requests return the same final result or wait/retry safely.
```

#### Paid-But-Not-Marked-Paid Recovery

```text
1. Mock /settle success.
2. Force Shopware state transition failure after settlement response is persisted.
3. Run recovery command/task.
Expected: stored settlement evidence allows transaction to be marked paid exactly once.
```

#### Unpaid-But-Fulfilled Prevention

```text
1. Mock /verify success.
2. Mock /settle failure.
Expected: order transaction is not paid; endpoint does not return paid success; no fulfillment event should be triggered by plugin.
```

#### Metadata Privacy Regression

```text
1. Create order with customer email, address, and product names.
2. Request x402 requirements.
Expected: requirements.description, resource, and extra contain only opaque IDs/order number/payment session ID; no PII or line-item names.
```

### 24.5 Verifiable Invariants

The implementation should encode these as automated tests and, where possible, database constraints.

| Invariant | Enforcement | Test evidence |
|---|---|---|
| One active payment session per order transaction | Unique index on `order_transaction_id` or active-session constraint | Integration test creating duplicate sessions. |
| One payment payload hash can only be used once | Unique index on `payment_payload_hash` | Replay test. |
| Payment amount comes from Shopware order transaction only | No amount accepted from request body | Unit + integration test with tampered amount. |
| `paid` requires successful settlement | State transition only after `settle.success=true` | Mock facilitator failure test. |
| Settlement is idempotent | DB row lock + idempotency key | Parallel retry test. |
| Quote expires | `expires_at` checked before verify/settle | Expired quote test. |
| Requirements are privacy-safe | metadata sanitizer + test corpus | PII regression test. |
| Recovery is possible after partial failure | settlement response persisted before transaction transition recovery | Forced transition failure test. |

### 24.6 CI Pipeline

Recommended CI jobs:

```text
composer validate
composer normalize, if used
PHP-CS-Fixer or ECS
PHPStan max practical level
Psalm, optional
PHPUnit unit suite
PHPUnit integration suite
Store API route integration suite
Mock facilitator contract suite
Mutation tests for validation/idempotency classes, optional but recommended
JavaScript/TypeScript SDK lint + tests, if SDK is shipped
OpenAPI schema validation, if API spec is shipped
Dual-install suite with SwagAgenticCommerce (section 26.5)
```

Suggested CI matrix:

| Axis | Values |
|---|---|
| Shopware | Latest supported 6.7 patch, next minor if feasible. |
| PHP | Supported PHP versions for the targeted Shopware release. |
| Database | MySQL/MariaDB version matching Shopware support policy. |
| Mode | testnet config, mainnet config with mocked facilitator only. |

Pull request gate:

```text
All static checks pass.
All unit tests pass.
All integration tests pass.
All mock-facilitator contract tests pass.
Security regression suite passes.
No new PII appears in generated payment requirements snapshots.
```

Nightly gate:

```text
Full E2E against Shopware container.
Optional real x402 testnet/facilitator run.
Dependency audit.
Race-condition stress test with parallel payment submissions.
```

### 24.7 Test Fixtures

Required fixtures:

```text
Sales channel with x402 payment method enabled.
Guest customer or test customer.
Product with deterministic gross/net price.
Shipping method with deterministic cost.
Currency fixture.
Merchant wallet fixture.
Supported asset fixture, e.g. USDC with 6 decimals.
Mock x402 payment requirements.
Valid mock X-PAYMENT payload.
Invalid mock payloads for wrong amount, wrong payTo, wrong network, expired authorization, duplicate nonce.
Mock facilitator responses for verify and settle.
```

### 24.8 Manual Verification Checklist

Before release, manually verify:

```text
Install plugin in a clean Shopware 6.7+ environment.
Configure test facilitator, network, asset, and merchant wallet.
Enable payment method for a sales channel.
Use curl/Postman/agent script to create cart and order.
Call x402 payment endpoint without X-PAYMENT and confirm HTTP 402.
Decode requirements and confirm amount/payTo/resource/expiry.
Submit signed testnet payment or mock payment payload.
Confirm order transaction becomes paid.
Confirm settlement hash is visible in DB/admin/support view.
Retry same request and confirm no second settlement.
Try wrong amount/payTo/network and confirm rejection.
Confirm logs contain no raw signatures, API keys, or customer PII.
```

### 24.9 Definition of Verified

The project is verified when the following evidence exists in CI or release artifacts:

```text
Test report for unit suite.
Test report for Shopware integration suite.
Test report for Store API route suite.
Mock facilitator contract test report.
Security regression test report covering replay, substitution, concurrency, and privacy.
Optional testnet settlement transaction hash for release candidate.
Static analysis report.
Manual release checklist signed off.
```

For MVP release, the minimum acceptable verification package is:

```text
100% pass rate on unit, integration, Store API, and mock facilitator tests.
Demonstrated happy-path paid transaction in a Shopware test environment.
Demonstrated duplicate retry without second settlement.
Demonstrated replay across orders is rejected.
Demonstrated settlement failure does not mark transaction paid.
Demonstrated requirements metadata is PII-safe.
```

---

## 25. Testing Research Sources

- Shopware is API-first/headless and Symfony-based, which supports kernel-level and Store API integration testing.
- Shopware core uses PHPUnit with separate unit and integration test suites, a test bootstrap, and `APP_ENV=test`.
- Existing Shopware Store API tests use a sales-channel browser with `HTTP_SW_CONTEXT_TOKEN` and call `/store-api/checkout/order`, which is the same style this plugin should use for `/store-api/x402/...` routes.
- x402 HTTP transport defines `402 Payment Required`, `X-PAYMENT`, and `X-PAYMENT-RESPONSE` as observable protocol behavior.
- x402 core specification defines the facilitator `/supported`, `/verify`, and `/settle` API shape.
- Recent x402 security research motivates explicit replay, context-binding, concurrency, state-synchronization, and privacy regression tests.

---

## 26. Compatibility with SwagAgenticCommerce (UCP Integration)

This section is normative for installations running this plugin together with Shopware's `SwagAgenticCommerce` plugin (UCP integration, backed by `ucp-php-sdk`). The design goal is layered:

```text
Tier 1 (MVP, mandatory): both plugins coexist with zero interference.
Tier 2 (MVP, mandatory): orders placed through UCP checkout can be paid with x402.
Tier 3 (later, optional): x402 participates in UCP payment discovery via a UCP payment handler bridge.
```

The x402 plugin must never hard-depend on `SwagAgenticCommerce` or `ucp-php-sdk`. All Tier 3 services must load conditionally (interface `class_exists` check or conditional service registration) so the plugin installs and runs identically with or without UCP.

### 26.1 Coexistence Matrix (Tier 1)

| Surface | SwagAgenticCommerce | This plugin | Verdict |
|---|---|---|---|
| HTTP routes | `/ucp/*`, `/ucp/v1/*`, `/ucp/mcp`, `/ucp/embedded/*`, `/.well-known/ucp`, `/.well-known/agent-card.json`, `/.well-known/oauth-authorization-server` | `/store-api/x402/*` only | No overlap. The x402 plugin must not register anything under `/ucp/*` or `/.well-known/*`. |
| DB tables | `swag_agentic_commerce_*`, `sales_channel_tracking_*` | `swag_x402_payment_session` | No overlap. |
| Shopware payment methods | None (UCP registers no Shopware payment method). | `swag_x402_agentic` | No overlap. |
| Payment method filtering | UCP does not filter or restrict available payment methods. | n/a | UCP checkout is unaffected by the x402 method existing in a sales channel. |
| Order transaction state | UCP performs no state machine transitions; orders are placed via core `CartOrderRoute` and the transaction stays `open`. | Transitions only through `X402TransactionStateService` after settlement. | No competing writers. |
| Core service decoration | Decorates only product-export and system-config reader services. | Must not decorate UCP or `ucp-php-sdk` services in MVP. | No overlap. |
| Event subscribers | None on `checkout.order.placed`, payment, or transaction-state events. | x402 payment flow is route-driven, not event-driven. | No overlap. |
| Response headers | Sets CSP/frame headers for `/ucp/embedded/*` via a global kernel listener. | `X-PAYMENT-RESPONSE` on `/store-api/x402/*` responses only. | No overlap. |

Required non-interference rules for this plugin:

```text
Do not modify, contribute to, or filter /.well-known/ucp in MVP.
Do not register a UCP payment handler that returns supportsTokenization() = true (x402 has no credential vaulting; fake tokenization violates the UCP plugin's documented non-goals).
Do not enable or depend on the UCP payment_tokenization capability.
Do not read or write swag_agentic_commerce_* tables.
Do not decorate Swag\AgenticCommerce or Ucp\Sdk services in MVP.
Scheduled session expiry must only touch x402 sessions and their order transactions, never orders or UCP checkout records.
```

### 26.2 How UCP Checkout Interacts with x402 (Facts)

Verified against the `SwagAgenticCommerce` implementation:

```text
UCP checkout completion (CheckoutCompleter) provisions a guest customer via the Store API register route.
The guest context token is generated server-side, stored in UCP session metadata, and never returned to the UCP client.
The order is placed through core CartOrderRoute with an empty RequestDataBag: no payment token, no payment method selection.
The SalesChannelContext is resolved with paymentMethodId = null, so the order gets the sales channel's DEFAULT payment method.
UCP never calls /store-api/handle-payment; the order transaction remains open.
The UCP client receives the orderId; the order deepLinkCode is stored in UCP checkout session metadata.
UCP checkout completion is idempotent (completion table + lock), and may publish an order.created webhook.
```

Consequences:

1. A UCP-placed order is a valid input for the x402 order-first flow: an order with an `open` transaction.
2. The x402 payment method will usually **not** be selected on that transaction (unless the merchant makes x402 the sales channel default).
3. The agent cannot present the order's context token, only `orderId` (+ `deepLinkCode`).

### 26.3 Paying UCP-Placed Orders (Tier 2)

Two spec-level accommodations make Tier 2 work without any UCP-specific code:

**Ownership proof B (deep link code).** As specified in section 7.2.0, `POST /store-api/x402/order/{orderId}/pay` accepts the order `deepLinkCode` as an alternative to the context token. This is generic Shopware behavior, not UCP-specific, but it is what makes UCP-originated payment possible.

**After-order payment method switch.** When the targeted order transaction is `open` and carries a different payment method, and the `Allow after-order payment method switch` setting is enabled, the plugin must switch the payment to x402 before creating the payment session, following Shopware's standard after-order payment semantics (`afterOrderEnabled: true`):

```text
verify order transaction state is open (not in_progress/paid/failed)
verify no other plugin's payment session/authorization is pending on the transaction
cancel the existing open transaction
create a new order transaction with the x402 payment method
bind the x402 payment session to the NEW orderTransactionId
```

Rules:

```text
The switch must happen inside the same DB transaction/lock scope as payment session creation.
The switch is one-way per session: an x402 payment session never survives a later method switch away from x402 (session must be expired if the transaction is cancelled externally).
If the switch setting is disabled, respond 409 SWAG_X402__PAYMENT_METHOD_NOT_SELECTED (section 14).
The amount is always re-read from the new order transaction; switching must not change totals.
```

Merchant guidance (documentation requirement, not code): a merchant running a UCP/agentic sales channel who wants agents to pay with x402 by default should either set `swag_x402_agentic` as that sales channel's default payment method, or rely on the after-order switch.

**End-to-end flow with both plugins installed:**

```text
Agent discovers shop via /.well-known/ucp (UCP) and /store-api/x402/capabilities (x402)
→ Agent runs UCP checkout (REST/A2A/MCP) and completes it
→ SwagAgenticCommerce places order; transaction open; default payment method
→ Agent calls POST /store-api/x402/order/{orderId}/pay with deepLinkCode
→ x402 plugin verifies ownership, switches transaction to x402 (if configured)
→ HTTP 402 with payment requirements
→ Agent signs, retries with X-PAYMENT
→ verify + settle via facilitator
→ transaction paid
```

Note: order status changes made by this plugin are visible to UCP clients through UCP's standard order read endpoints (`GET /ucp/v1/orders/{id}`), because both plugins operate on the same Shopware order aggregate. No direct integration is needed for that.

### 26.4 UCP Payment Handler Bridge (Tier 3, Later Scope)

`ucp-php-sdk` defines the extension point `Ucp\Sdk\Contract\PaymentHandlerInterface` (service tag `ucp_sdk.payment_handler`, auto-configured). Constraints observed in the SDK and plugin:

```text
Handler ids must be globally unique; the SDK registry throws on duplicates. Reserve: com.shopware.x402 (final id TBD).
The bundled invoice handler uses com.shopware.invoice; do not collide.
payment_handlers in /.well-known/ucp is only exposed when at least one handler supports tokenization AND the sales channel enables the payment_tokenization capability.
prepareInstrument() is currently not consumed during UCP order placement; UCP orders always use the context's default payment method.
```

Therefore the bridge is deferred until the UCP SDK consumes payment instruments during checkout. When implemented, it must:

```text
Implement PaymentHandlerInterface with supportsTokenization() = false — x402 uses per-order signed authorizations (EIP-3009), not vaulted credentials. Never return placeholder tokens.
prepareInstrument() maps the instrument to the configured swag_x402_agentic payment method id.
Register conditionally: only when Ucp\Sdk\Contract\PaymentHandlerInterface exists at runtime.
Never toggle the UCP payment_tokenization capability on behalf of the merchant.
Optionally use ucp_sdk.checkout_response_augmenter to attach the x402 payment endpoint URL and deepLinkCode hint to completed UCP checkouts, so agents do not need out-of-band knowledge.
```

### 26.5 Dual-Install Verification (extends Section 24)

Add a CI job that installs **both** plugins into the same Shopware instance:

```text
Both plugins install, migrate, and boot together (no service/container conflicts).
x402 plugin installs and boots WITHOUT SwagAgenticCommerce / ucp-php-sdk present (no hard dependency).
/.well-known/ucp output is byte-identical before and after installing the x402 plugin (Tier 1 non-interference).
POST /ucp/v1/tokenize behavior is unchanged by the x402 plugin (still controlled 501 when no tokenizing handler exists).
UCP checkout completes normally in a sales channel where swag_x402_agentic is active and even where it is the default payment method.
Full Tier 2 flow: UCP checkout → orderId + deepLinkCode → x402 pay route → 402 → X-PAYMENT → mock facilitator settle → transaction paid.
After-order switch: UCP order with invoice default is switched to x402, old transaction cancelled, new transaction paid; totals unchanged.
Ownership negative test: x402 pay route rejects a UCP-placed order when called with a foreign context token and no/wrong deepLinkCode.
Replay negative test: payload settled for a UCP-placed order A is rejected for UCP-placed order B.
x402 session expiry does not touch UCP checkout completion records or cancel UCP-readable orders (only transactions per config).
```

### 26.6 UCP-Related Open Questions

| Area | Question |
|---|---|
| Payment method selection | Should the plugin recommend x402 as default payment method for agentic/UCP sales channels, or rely solely on the after-order switch? |
| Discovery | Should the x402 endpoint be advertised to agents via native agentic discovery documents (`/agents.md`, `llms.txt`) as an interim measure until UCP supports non-tokenization payment schemes? |
| UCP roadmap | When the UCP SDK wires `prepareInstrument()` into checkout completion, should the Tier 3 bridge become MVP scope of a follow-up release? |
| Webhooks | Should x402 settlement trigger an order webhook consistent with UCP's `order.created` webhook (e.g. `order.updated`)? Currently out of scope. |
