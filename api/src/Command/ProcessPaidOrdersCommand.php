<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Payment\ProcessPaidOrderOutbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'miksprit:payments:process-paid-orders', description: 'Provisions Wi-Fi access for verified, paid orders.')]
final class ProcessPaidOrdersCommand extends Command
{
    public function __construct(private readonly ProcessPaidOrderOutbox $processor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of outbox messages to claim.', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $output->writeln('<error>--limit must be between 1 and 500.</error>');
            return Command::INVALID;
        }
        $processed = 0;
        $hadFailure = false;
        while ($processed < $limit) {
            $result = $this->processor->processOne();
            if ($result === 'idle') break;
            ++$processed;
            if ($result === 'completed') $output->writeln('<info>Paid order access provisioned.</info>');
            elseif ($result === 'retry') { $output->writeln('<comment>Router credentials or transport are unavailable; the outbox message was released for a safe retry.</comment>'); $hadFailure = true; }
            elseif ($result === 'failed') { $output->writeln('<comment>Paid order provisioning failed; inspect the order and router configuration.</comment>'); $hadFailure = true; }
            else { $output->writeln('<error>Provisioning outcome is ambiguous; the message is held for manual RouterOS reconciliation.</error>'); $hadFailure = true; }
        }
        if ($processed === 0) $output->writeln('No paid orders are waiting for provisioning.');
        return $hadFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
