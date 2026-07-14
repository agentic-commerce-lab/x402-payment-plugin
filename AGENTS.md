# Agent Notes

This repository holds the Shopware 6 x402 payment plugin (working name
`SwagX402Payments`). The spec, [docs/spec.md](docs/spec.md),
is the single source of truth for scope, architecture, API contracts,
data model, security requirements, and testing strategy. Read the relevant
spec sections before making any change, whether to the spec or (later) to
implementation code.

## What This Plugin Is

A Shopware 6.7+ payment plugin that lets AI agents and other headless API
clients pay Shopware orders through the x402 protocol (HTTP `402 Payment
Required`, `exact` scheme, facilitator-based verify/settle). The flow is
order-first: the agent places a normal Store API order, then pays the open
order transaction via `POST /store-api/x402/order/{orderId}/pay`.

Spec section map:

| Topic | Section |
| --- | --- |
| Goals / non-goals | 2 |
| Store API contract (`/store-api/x402/*`) | 7 |
| Checkout flows (incl. UCP-originated) | 8 |
| Data model (`swag_x402_payment_session`) | 9 |
| Requirement binding and quote hashing | 11 |
| Services | 12 |
| Merchant configuration | 13 |
| Security requirements | 15 |
| Testing / verification | 19, 24 |
| SwagAgenticCommerce (UCP) compatibility | 26 |

## Invariants (Never Violate)

These come from spec sections 10, 11, 15, and 24 and are the reason this
plugin is safe to run. Any change — spec or code — that weakens one of them
needs explicit sign-off from the user, not a silent edit:

- x402 pays a Shopware **order transaction**, never a cart or arbitrary
  request.
- A transaction becomes `paid` only after successful facilitator settlement,
  never after signature verification alone.
- Payment amounts always come from the persisted Shopware order transaction,
  never from client input.
- One payment payload hash pays at most one order; replay and cross-order
  substitution are rejected before facilitator calls.
- Settlement is idempotent under concurrent retries (row lock +
  idempotency key).
- No customer PII (name, email, address, cart contents, product names) in
  x402 requirements, metadata, facilitator calls, or logs.
- The pay route discloses nothing without a valid ownership proof: the
  caller's `sw-context-token` or the order `deepLinkCode` (spec 7.2.0).

## SwagAgenticCommerce / UCP Compatibility

Spec section 26 is normative. The short version:

- Zero interference: never touch `/ucp/*` or `/.well-known/*` routes,
  `swag_agentic_commerce_*` tables, or `Swag\AgenticCommerce` / `Ucp\Sdk`
  services. Installing this plugin must not change `/.well-known/ucp` output.
- No hard dependency: this plugin must install and run without
  SwagAgenticCommerce or `ucp-php-sdk` present. Any UCP bridge code loads
  conditionally.
- UCP-placed orders are paid via the deep-link ownership proof plus the
  after-order payment method switch (spec 26.3). This works through standard
  Shopware order data only — no UCP APIs.
- Never register a UCP payment handler claiming `supportsTokenization() ===
  true` and never return placeholder tokens; x402 has no credential vaulting.

Reference checkouts on this machine (verify against code, do not trust stale
spec claims): the UCP plugin lives in `../agentic-commerce`, its SDK in
`../ucp-php-sdk`. If you change spec section 3.5 or 26, re-verify the cited
behavior (routes, tags, handler registry, checkout completion) against those
repos first.

## Working on the Spec

- Keep section numbering stable; new content goes into existing sections or
  numbered subsections. Update cross-references when you move anything.
- The spec states verified facts about external systems (x402 protocol, UCP
  plugin behavior). Mark unverified assumptions as open questions (sections
  22 and 26.6) instead of asserting them.
- Keep the layered style: normative rules as short `text` blocks or tables,
  narrative kept brief.

## Quality Gate

This project runs the ACL quality gate in strict mode for one gated root: the
Shopware plugin project at this repository root. The PHP target is 8.2 (the
minimum supported version; do not use 8.3+-only features in `src`), source
code lives under `src`, and CI is the authority.

- Run the unit suite: `composer run test` (PHPUnit, `tests/Unit`)
- Format + lint a change: `composer run format:check && composer run lint`
- Add type checking for code changes: `composer run typecheck` (Mago analyze)
- Broad refactor or gate change: `composer run quality`
- Dependency/import cleanup: `composer run quality:depcheck`
- Advisory visibility: `composer run quality:maintainability` and
  `composer run quality:hotspots`
- Run the narrowest useful check for the change; use `composer run quality`
  before claiming the full gate passes.

PHP files must use `declare(strict_types=1)`. Keep code inside the gate
thresholds: cyclomatic complexity 10, nesting depth 4, parameter count 5, and
about 400 lines per file. Use PSR-3 logging through Shopware/Symfony services;
never use `echo`, `var_dump`, `print_r`, or `dd` in application code. Validate
Store API and facilitator boundary data explicitly with Shopware/Symfony
validation or small domain DTOs. Persistence uses Shopware DAL repositories and
migrations; do not introduce Doctrine ORM.

## Working on the Implementation

Follow the spec's package structure (section 5.2) and phase plan
(section 20). Shopware plugin conventions, aligned with the sibling
`agentic-commerce` repo:

- Target Shopware 6.7+ only. Implement `AbstractPaymentHandler`; register the
  handler with the `shopware.payment.method` tag; create the payment method
  on install, deactivate (never delete) on uninstall.
- Shopware uses its own Data Abstraction Layer. Use DAL `Criteria` and
  `EntityRepository`; do not add Doctrine ORM, annotations, or ORM-style
  repositories.
- Customer-facing runtime flows go through Store API route boundaries where
  they exist. Direct repository access is for plugin-owned data
  (`swag_x402_payment_session`) and admin/internal metadata.
- Controllers never mutate transaction state directly — only
  `X402TransactionStateService` does (spec 12.6).
- Treat the facilitator as an untrusted boundary: validate locally before
  `/verify`, validate response schemas, and never mark paid on timeout or
  malformed responses.
- Keep services unit-testable without external systems; translate framework
  objects (Request, HTTP, DB) at the edge.
- Follow the test architecture in spec 24: pure unit tests, Shopware kernel
  integration tests (`KernelBrowser` + `HTTP_SW_CONTEXT_TOKEN`), mock
  facilitator contract tests, and E2E agent tests. The security and
  dual-install suites (24.4, 26.5) are mandatory, not optional.
- The unit + security + facilitator-contract suites live in `tests/Unit` and
  run via `composer run test`; CI runs them as part of the quality gate.
  Shared fixtures are in `tests/Unit/Support/X402Fixtures.php` — payload and
  session defaults are mutually consistent, so tests break exactly one
  binding at a time. Kernel integration and dual-install suites do not exist
  yet.
- Validation: run `composer run test` plus the narrowest relevant gate
  command; use `composer run quality` before claiming the full gate passes.

## Scope Discipline

- Prefer the least invasive change that addresses the root cause; fix bugs at
  the boundary where the cause lives.
- Keep PRs focused; use conventional commit style; keep titles short.
- Do not expand MVP scope (spec 2.2 non-goals: refunds, multi-token, dynamic
  FX, storefront wallet UI, pay-before-order) without the user deciding to.
