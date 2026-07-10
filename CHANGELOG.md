# 1.0.0

- Native `swag_x402_agentic` payment method for Shopware 6.7+ (x402 protocol v1, `exact` scheme)
- Store API routes: `GET /store-api/x402/capabilities`, `POST /store-api/x402/order/{orderId}/pay` (HTTP 402 handshake), `GET /store-api/x402/payment-session/{sessionId}`
- Order ownership proof via context token or order `deepLinkCode` (supports UCP/SwagAgenticCommerce-placed orders)
- After-order payment method switch to x402 for open transactions
- Facilitator integration (`/verify`, `/settle`) with replay protection, quote binding, idempotent settlement, and pessimistic locking
- Storefront fallback: "Pay now with wallet" page for browser wallets
- Scheduled expiry of stale payment sessions
- `x402:recover-settlements` console command: marks order transactions paid from persisted settlement evidence when the state transition failed after successful settlement
