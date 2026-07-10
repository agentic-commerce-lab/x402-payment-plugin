# x402 Demo Agent

Runnable end-to-end client for the SwagX402Payments plugin (spec section 18).
It performs a complete headless purchase: capability discovery, cart, guest
registration, order placement, and the x402 payment handshake using the
official `x402-fetch` client with real EIP-3009 signing.

## Setup

```bash
cd examples/demo-agent
npm install
cp .env.example .env   # fill in SW_ACCESS_KEY and PAYER_PK
```

Need wallets? `npm run wallet` generates a key pair (run twice: buyer + merchant);
`npm run wallet -- 0x<key>` derives the address for an existing key.

Prerequisites in Shopware:

- Plugin installed and configured (facilitator, network, asset, merchant wallet).
- Payment method "x402 Agentic Payment" active; ideally assigned to the sales
  channel (if not, the after-order payment method switch handles it).
- Sales channel currency matches the configured `supportedCurrency`.
- At least one purchasable product.

## Run

```bash
npm run agent   # loads .env automatically
```

Expected output ends with the settlement transaction hash and
`order <number> is PAID`.

## Modes

**Mock facilitator (no chain):** configure the plugin with the local mock
facilitator (see the project docs). The agent still signs for real; the mock
skips on-chain verification. Any funded or unfunded key works.

**Base Sepolia (as real as it gets):** configure the plugin with
`facilitatorBaseUrl=https://x402.org/facilitator`, `network=base-sepolia`,
USDC `0x036CbD53842c5426634e7929541eC2318f3dCF7e` (6 decimals), and fund the
payer key with test USDC from https://faucet.circle.com. The script prints a
Basescan explorer link on success.

**UCP-style ownership (`DEEP_LINK_ONLY=1`):** pays the order using only
`orderId` + `deepLinkCode`, without the Store API context token - the same
proof an agent has after completing a UCP checkout (spec section 26.3).

## Negative tests

After a successful run:

- Re-run the final request with the same `Idempotency-Key` and `X-PAYMENT`:
  returns the same paid result, no second settlement.
- Send the captured `X-PAYMENT` to a different order's pay route: rejected
  with `SWAG_X402__PAYLOAD_REPLAYED`.
