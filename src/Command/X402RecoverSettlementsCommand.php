<?php

declare(strict_types=1);

namespace Swag\X402Payments\Command;

use Shopware\Core\Framework\Context;
use Swag\X402Payments\Core\X402\X402SettlementRecoveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recovery path for the worst partial failure (spec section 17.2): the
 * facilitator settled the payment, the evidence is persisted, but the
 * Shopware transaction never became paid.
 */
#[AsCommand(
    name: 'x402:recover-settlements',
    description: 'Marks order transactions paid from persisted x402 settlement evidence',
)]
class X402RecoverSettlementsCommand extends Command
{
    public function __construct(
        private readonly X402SettlementRecoveryService $recoveryService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('session-id', null, InputOption::VALUE_REQUIRED, 'Recover a single payment session by ID');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report recoverable sessions without transitioning');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sessionId = $input->getOption('session-id');
        $sessionId = \is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $results = $this->recoveryService->recover(Context::createCLIContext(), $sessionId, $dryRun);

        if ($results === []) {
            $io->success('No settled x402 payment sessions found.');

            return self::SUCCESS;
        }

        $io->table(['Payment session', 'Order number', 'Order transaction', 'Status', 'Detail'], array_map(
            static fn($result): array => [
                $result->paymentSessionId,
                $result->orderNumber,
                $result->orderTransactionId,
                $result->status,
                $result->detail,
            ],
            $results,
        ));

        foreach ($results as $result) {
            if ($result->needsAttention()) {
                $io->error('At least one recovery failed - see the table and logs above.');

                return self::FAILURE;
            }
        }

        $io->success(\sprintf('Processed %d settled session(s).', \count($results)));

        return self::SUCCESS;
    }
}
