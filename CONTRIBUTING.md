# Contributing

Thank you for your interest in contributing. This document describes the local
setup, the quality gate, and the pull request workflow.

The technical specification, [docs/spec.md](docs/spec.md), is the single source
of truth for scope, architecture, API contracts, and security requirements.
Read the relevant spec sections before making a change. Additional working
conventions for this repository are documented in [AGENTS.md](AGENTS.md).

## Local Setup

The plugin targets Shopware 6.7+ and PHP 8.3. For manual testing it is mounted
into a running Shopware development stack (see the
[Getting started](README.md#getting-started) section of the README); the test
and quality suites only need PHP and Composer:

```bash
composer install
```

If you do not have PHP on your host, run the commands inside your Shopware
`web` container instead:

```bash
docker compose exec web sh -c 'cd custom/plugins/SwagX402Payments && composer install'
```

## Main Commands

Run the test suites (unit + security + facilitator contract):

```bash
composer run test
```

Run the full quality gate:

```bash
composer run quality
```

Useful targeted commands:

```bash
composer run format:check   # code style
composer run lint           # linting
composer run typecheck      # static analysis (Mago)
composer run quality:depcheck
```

Run the narrowest useful check for your change; run `composer run quality`
before claiming the full gate passes. CI is the authority.

## Working Rules

- PHP files must use `declare(strict_types=1)`.
- Never weaken a security invariant listed in [AGENTS.md](AGENTS.md) (replay
  protection, settlement-before-paid, server-side amounts, no PII in x402
  payloads or logs) without explicit maintainer sign-off.
- Use the Shopware DAL (`Criteria`, `EntityRepository`); do not introduce
  Doctrine ORM.
- Keep services unit-testable without external systems; translate framework
  objects (Request, HTTP, DB) at the edge.
- Treat the facilitator as an untrusted boundary: validate responses and never
  mark an order paid on timeout or malformed data.
- Do not touch `/ucp/*` or `/.well-known/*` routes or any
  `SwagAgenticCommerce` services; the optional UCP bridge must load
  conditionally.
- Stay within the gate thresholds: cyclomatic complexity 10, nesting depth 4,
  parameter count 5, about 400 lines per file.

## Pull Requests

Before opening a PR:

1. run `composer run test` and `composer run quality`
2. keep the PR focused on one change; fix bugs at the boundary where the
   cause lives
3. use conventional commit style with short titles
4. update [docs/spec.md](docs/spec.md) when you change behavior it describes

## License

By contributing, you agree that your contributions are licensed under the
[MIT License](LICENSE).
