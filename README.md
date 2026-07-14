# SwagX402Payments

A **research preview** of a payment plugin for Shopware 6.7+ that enables **AI agents
and automated clients to pay for orders programmatically** — no checkout form, no
credit card, no manual interaction.

The buyer (typically an agent) places a regular order through Shopware's Store API
and then settles it with a cryptographic signature. The payment is made in a
stablecoin and transferred from the
buyer's wallet to the shop's wallet. The entire exchange takes place over plain HTTP.

Full technical specification: [docs/spec.md](docs/spec.md)

## The concepts in two minutes

These five foundational terms are all you need to use and test this plugin:

| Term | What it means here |
| --- | --- |
| **x402** | An open payment protocol built on the HTTP status code `402 Payment Required`. The shop responds "this order costs 42.99, pay to this address", the buyer returns a signed payment, and the exchange is complete. |
| **Wallet** | Simply a key pair. The "address" (starting with `0x…`) works like an account number; the "private key" is the secret used to sign payments. No application or registration is needed — a wallet is created by generating a random key. |
| **USDC** | A stablecoin: a digital token where 1 USDC equals 1 US dollar. This is the currency the buyer pays with. |
| **Base Sepolia** | A **test network** — a replica of a real blockchain that runs on valueless test tokens, which makes it ideal for development and testing. (It is the test version of "Base", Coinbase's network, and not the same as "Ethereum Sepolia".) |
| **Facilitator** | A web service that verifies the buyer's signature and executes the transfer. The plugin communicates with it through two HTTP calls (`/verify`, `/settle`); you never interact with a blockchain directly. |

The payment flow, in words:

```
Agent places an order (regular Shopware Store API checkout)
→ Agent calls POST /store-api/x402/order/{orderId}/pay
→ Shop answers HTTP 402: "pay 42.99 USDC to wallet 0xABC…"
→ Agent signs that payment with its private key
→ Agent repeats the call with the signature in an X-PAYMENT header
→ Plugin has the facilitator verify and execute the transfer
→ Order is marked as paid
```

## Getting started

There are two testing levels, and they build on each other:

- **Level 1 — local stub facilitator (about 10 minutes):** everything runs locally,
  with no internet access and no cryptocurrency involved. This verifies that the
  plugin and the payment flow work.
- **Level 2 — real protocol on the test network:** real signatures and a real
  transfer, visible on a public block explorer — but using free, valueless test USDC.

Both levels assume a running Shopware 6.7 development stack (the
[docker-dev](https://developer.shopware.com/docs/guides/installation/setups/docker.html)
setup) with at least one product in the catalog.

### Step 1: Install the plugin (both levels)

Mount the plugin into your Shopware installation and install it:

```yaml
# compose.override.yaml, under services -> web:
volumes:
  - /path/to/x402-plugin:/var/www/html/custom/plugins/SwagX402Payments
```

```bash
docker compose up -d web
docker compose exec web bin/console plugin:refresh
docker compose exec web bin/console plugin:install --activate SwagX402Payments
docker compose exec web bin/console cache:clear
```

Then open the Administration (`http://localhost:8000/admin`):
**Settings → Payment methods → "x402 Agentic Payment" → activate**, and assign the
method to your sales channel (Sales channel → Payment methods).

### Step 2, Level 1: Test with a stub facilitator (no crypto)

Instead of a real payment service, run a small PHP script that always responds
"payment is valid". This allows you to test the complete order-and-pay flow offline:

```bash
docker compose exec web sh -c 'cat > /tmp/facilitator.php << "EOF"
<?php
header("Content-Type: application/json");
if (str_contains($_SERVER["REQUEST_URI"], "verify")) {
    echo json_encode(["isValid" => true, "payer" => "0xPayer"]);
} else {
    echo json_encode(["success" => true, "payer" => "0xPayer", "transaction" => "0x" . bin2hex(random_bytes(8))]);
}
EOF
php -S 127.0.0.1:9402 /tmp/facilitator.php &'
```

Point the plugin at it (the wallet and asset values can be arbitrary in this mode):

```bash
docker compose exec web sh -c '
bin/console system:config:set SwagX402Payments.config.enabled true
bin/console system:config:set SwagX402Payments.config.facilitatorBaseUrl http://127.0.0.1:9402
bin/console system:config:set SwagX402Payments.config.merchantWalletAddress 0x1111111111111111111111111111111111111111
bin/console system:config:set SwagX402Payments.config.assetAddress 0x2222222222222222222222222222222222222222
bin/console system:config:set SwagX402Payments.config.supportedCurrency EUR'
```

> `supportedCurrency` must match your sales channel currency (default Shopware
> development setups use EUR, so EUR is appropriate here).

Now continue with **Step 3** to run the demo agent.

### Step 2, Level 2: Test on the public test network

This is identical to a production setup, except that all funds are valueless test tokens.

**a) Create two wallets.** A wallet is simply a random key. The demo agent includes a
helper that generates one and prints both the private key and its address:

```bash
cd examples/demo-agent && npm install
npm run wallet         # run twice: once for the buyer, once for the shop
```

Keep the buyer's **private key** (used in `.env` as `PAYER_PK`) and the shop's
**address** (used in the plugin configuration). Alternatively, use any address you
already own (e.g. from MetaMask).

**b) Obtain free test USDC.** Go to <https://faucet.circle.com>, select
**Base Sepolia**, paste the buyer's address, and free test USDC is sent to it.
(The buyer needs nothing else — no "gas" and no ETH; the facilitator covers those costs.)

**c) Configure the plugin** to use the public test facilitator operated by Coinbase:

```bash
docker compose exec web sh -c '
bin/console system:config:set SwagX402Payments.config.enabled true
bin/console system:config:set SwagX402Payments.config.facilitatorBaseUrl https://x402.org/facilitator
bin/console system:config:set SwagX402Payments.config.network base-sepolia
bin/console system:config:set SwagX402Payments.config.assetAddress 0x036CbD53842c5426634e7929541eC2318f3dCF7e
bin/console system:config:set SwagX402Payments.config.assetSymbol USDC
bin/console system:config:set SwagX402Payments.config.assetDecimals 6
bin/console system:config:set SwagX402Payments.config.merchantWalletAddress <YOUR-SHOP-WALLET-ADDRESS>
bin/console system:config:set SwagX402Payments.config.supportedCurrency USD'
```

The `0x036C…` value is the official USDC token contract on Base Sepolia — it is the
same for everyone and can be copied as is.

> Because USDC is a dollar-denominated token and the plugin performs no currency
> conversion, your sales channel must use **USD** in this mode
> (Admin → Settings → Currencies, then assign USD to the sales channel).

### Step 3: Run the demo agent (both levels)

[examples/demo-agent](examples/demo-agent/) is a ready-made "AI agent" script. It
behaves like a real API client: it discovers the shop's x402 support, fills a cart,
registers as a guest, places the order, and pays it with a genuine cryptographic
signature.

```bash
cd examples/demo-agent
npm install
cp .env.example .env
```

Open `.env` and provide two values:

- `SW_ACCESS_KEY` — Admin → Sales channels → your channel → **API access** → copy the access key.
- `PAYER_PK` — the buyer's private key (`0x` + the value from `openssl rand -hex 32`).
  In Level 1, any random key works, funded or not.

Then run it:

```bash
npm run agent   # loads .env automatically
```

**Expected result:** the script walks through 8 numbered steps and ends with

```
status: paid
settlement tx: 0x84f3…
Done: order 10042 is PAID.
```

On Level 2 it also prints a link such as `https://sepolia.basescan.org/tx/0x84f3…` —
opening it shows the actual test-USDC transfer from the buyer's wallet to the shop's
wallet, recorded on a public network. In the Shopware Administration, the order's
payment status now shows **Paid**.

## Verifying the security properties

Each of the following demonstrates a security property of the plugin:

- **Run the script twice against the same order** (re-send the final request with the
  same headers): you receive the same "paid" response, but no second payment is executed.
- **Replay a payment against another order:** capture the `X-PAYMENT` header from one
  order and send it to a different order's pay endpoint → rejected with
  `SWAG_X402__PAYLOAD_REPLAYED`.
- **Pay without proof of ownership:** call the pay endpoint without the order's
  `deepLinkCode` and without the session that placed the order → `403`. Add
  `?deepLinkCode=…` (the demo agent prints it) → the call succeeds. This is how agents
  pay for orders placed through other channels, e.g. UCP.
- **Let the quote expire:** wait past the configured timeout (default 5 minutes) and
  attempt to pay → `SWAG_X402__QUOTE_EXPIRED`; a fresh 402 response provides a new quote.

## FAQ

**Can customers pay in the storefront with this?** Yes. After placing an order with
the x402 method, the confirmation page shows a **"Pay now with wallet"** button that
leads to `/x402/pay/{orderId}`. There the customer connects a browser wallet
(e.g. MetaMask on the Base Sepolia network with test USDC), signs the payment, and
the order is marked as paid — with no gas costs for the buyer. Machine buyers use the
Store API route directly instead.

**Is real money involved at any point during testing?** No. Level 1 never touches a
network; Level 2 uses a test network whose tokens are free and hold no value.

**Do I need to install MetaMask or any other crypto software?** No. A private key
generated with `openssl` and the demo script are sufficient.

**Something failed — where should I look?** Check the error body of the failing HTTP
call (the demo agent prints it) and `var/log/dev.log` in the Shopware container.
Common causes: the plugin is not configured for the sales channel
(`SWAG_X402__NOT_CONFIGURED`), a currency mismatch
(`SWAG_X402__CURRENCY_NOT_SUPPORTED` — the sales channel currency must equal
`supportedCurrency`), or the payment method is not activated or assigned.

## Testing together with UCP (SwagAgenticCommerce)

x402 does **not** appear in `/.well-known/ucp` under `payment_handlers` — by design
(spec section 26). UCP only advertises handlers that support credential
tokenization, and x402 uses per-order signed authorizations instead of vaulted
credentials. The integration is order-first: UCP places the order, x402 pays it.

```
UCP checkout (REST/A2A/MCP) → order placed, transaction open (default payment method)
→ agent calls POST /store-api/x402/order/{orderId}/pay?deepLinkCode=…
→ plugin switches the transaction to x402 (after-order switch)
→ HTTP 402 → sign → X-PAYMENT → verify/settle → paid
```

Prerequisites on a dev stack:

1. Both plugins installed and active; x402 configured (see above).
2. The UCP sales channel's **signature policy** set to `log` so unsigned curl
   requests pass (Admin API: `PUT /api/_admin/ucp/sales-channels/{id}/config` —
   note this endpoint replaces the whole config object, so send all fields).
3. The sales channel **domain** currency must equal the x402 `supportedCurrency`.
   UCP resolves its context through the domain, so a domain pinned to EUR
   produces EUR orders even if the channel default is USD.

Run a UCP checkout (every `/ucp/*` call needs `UCP-Agent` and `Idempotency-Key`
headers):

```bash
UA='UCP-Agent: platform; profile="http://localhost:8000/.well-known/ucp"'
# 1. find a product
curl -s -X POST localhost:8000/ucp/v1/catalog/search -H "$UA" -H "Idempotency-Key: $(uuidgen)" \
  -H 'Content-Type: application/json' -d '{"query":"","limit":3}'
# 2. create a checkout session (line items + buyer + shipping in one call)
curl -s -X POST localhost:8000/ucp/v1/checkout-sessions -H "$UA" -H "Idempotency-Key: $(uuidgen)" \
  -H 'Content-Type: application/json' -d '{
    "line_items": [{ "item": { "id": "<product-id>" }, "quantity": 1 }],
    "buyer": { "email": "agent@example.test" },
    "fulfillment": { "type": "shipping", "extra": { "shipping_address": {
      "street": "Agent Street 1", "zipcode": "48624", "city": "Schoeppingen", "country_code": "DE" } } }
  }'
# 3. complete it ("payment" key is required, an empty object is fine without AP2)
curl -s -X POST localhost:8000/ucp/v1/checkout-sessions/<checkout-id>/complete \
  -H "$UA" -H "Idempotency-Key: $(uuidgen)" -H 'Content-Type: application/json' -d '{"payment":{}}'
```

The completion response contains `order.id`. **Known gap:** the current UCP
plugin does not expose the order `deepLinkCode` to the client (it is stored
only in server-side session metadata), so for now fetch it merchant-side:

```bash
docker compose exec database mariadb -uroot -proot shopware -N -e \
  "SELECT deep_link_code FROM \`order\` WHERE id = UNHEX('<order-id-without-dashes>');"
```

Then pay the order:

```bash
cd examples/demo-agent
npm run pay-order -- <order-id> <deep-link-code>
```

Afterwards the order has two transactions: the original default-method one
(`cancelled`) and a `swag_x402_agentic` one (`paid`) — that is the after-order
switch working as specified.

## For developers

Quality gate and coding conventions: see [AGENTS.md](AGENTS.md).

```bash
composer install
composer run test      # unit + security + facilitator contract suites
composer run quality   # static analysis gate
```

If a payment settled on-chain but the order transaction was never marked as paid
(e.g. the process terminated mid-transition), recover it from the persisted
settlement evidence:

```bash
bin/console x402:recover-settlements --dry-run   # report only
bin/console x402:recover-settlements             # mark paid
```
