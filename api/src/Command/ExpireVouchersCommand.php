<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Router\ExpireDueVouchers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'miksprit:vouchers:expire', description: 'Removes expired paid Wi-Fi voucher accounts from RouterOS.')]
final class ExpireVouchersCommand extends Command
{
    public function __construct(private readonly ExpireDueVouchers $expiration)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum vouchers to inspect.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) return Command::INVALID;
        $result = $this->expiration->execute($limit);
        $output->writeln(sprintf('Expired %d voucher(s); %d skipped for retry; %d due voucher(s) need router reconciliation.', $result['expired'], $result['skipped'], $result['unassigned']));
        return $result['skipped'] > 0 || $result['unassigned'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
