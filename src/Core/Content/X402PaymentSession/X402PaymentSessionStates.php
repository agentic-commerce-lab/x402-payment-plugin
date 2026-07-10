<?php

declare(strict_types=1);

namespace Swag\X402Payments\Core\Content\X402PaymentSession;

final class X402PaymentSessionStates
{
    public const STATE_CREATED = 'created';
    public const STATE_REQUIREMENTS_ISSUED = 'requirements_issued';
    public const STATE_PAYLOAD_RECEIVED = 'payload_received';
    public const STATE_VERIFIED = 'verified';
    public const STATE_SETTLED = 'settled';
    public const STATE_EXPIRED = 'expired';
    public const STATE_VERIFY_FAILED = 'verify_failed';
    public const STATE_SETTLEMENT_FAILED = 'settlement_failed';

    // settlement_failed stays payable: facilitator/chain errors are often
    // transient and the agent must be able to retry (spec sections 10 and 14).
    // Only settled and expired sessions are terminal.
    public const PAYABLE_STATES = [
        self::STATE_CREATED,
        self::STATE_REQUIREMENTS_ISSUED,
        self::STATE_PAYLOAD_RECEIVED,
        self::STATE_VERIFIED,
        self::STATE_VERIFY_FAILED,
        self::STATE_SETTLEMENT_FAILED,
    ];

    // Proof kind labels, not credentials.
    // @mago-expect lint:no-literal-password
    public const OWNERSHIP_PROOF_CONTEXT_TOKEN = 'context_token';
    public const OWNERSHIP_PROOF_DEEP_LINK_CODE = 'deep_link_code';

    private function __construct() {}
}
