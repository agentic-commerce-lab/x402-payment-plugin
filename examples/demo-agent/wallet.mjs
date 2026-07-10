#!/usr/bin/env node
/**
 * Wallet helper for testing.
 *
 *   node wallet.mjs              generate a new key pair
 *   node wallet.mjs 0x<key>      derive the address for an existing private key
 *
 * Test use only: never reuse these keys for real funds.
 */

import { randomBytes } from "node:crypto";
import { privateKeyToAccount } from "viem/accounts";

const input = process.argv[2];

const privateKey = input ?? `0x${randomBytes(32).toString("hex")}`;

if (!/^0x[0-9a-fA-F]{64}$/.test(privateKey)) {
  console.error("Invalid private key: expected 0x followed by 64 hex characters.");
  process.exit(1);
}

const account = privateKeyToAccount(privateKey);

if (!input) {
  console.log("Generated a new test wallet:\n");
  console.log(`  private key: ${privateKey}`);
} else {
  console.log("Derived from the given private key:\n");
}
console.log(`  address:     ${account.address}\n`);
console.log("Buyer wallet:    put the private key into .env as PAYER_PK and fund the");
console.log("                 address with test USDC at https://faucet.circle.com (Base Sepolia).");
console.log("Merchant wallet: set the address as SwagX402Payments.config.merchantWalletAddress.");
