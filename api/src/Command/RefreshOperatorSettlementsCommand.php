<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Payment\RefreshOperatorSettlements;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'miksprit:payments:settle-operator-sales', description: 'Reconciles FedaPay settlements and credits operator wallets.')]
final class RefreshOperatorSettlementsCommand extends Command
{
    public function __construct(private readonly RefreshOperatorSettlements $settlements) { parent::__construct(); }
    protected function configure(): void { $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum approved sales to recheck.', '100'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) return Command::INVALID;
        $result = $this->settlements->execute($limit);
        $output->writeln(sprintf('Réglées : %d; en attente : %d; erreurs à rapprocher : %d.', $result['settled'], $result['pending'], $result['failed']));
        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
