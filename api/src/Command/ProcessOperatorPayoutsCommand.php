<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Payment\ProcessOperatorPayouts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'miksprit:payouts:process', description: 'Submits or reconciles reserved operator Mobile Money payouts.')]
final class ProcessOperatorPayoutsCommand extends Command
{
    public function __construct(private readonly ProcessOperatorPayouts $processor) { parent::__construct(); }
    protected function configure(): void { $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum payout requests to inspect.', '25'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) return Command::INVALID;
        $hadFailure = false; $processed = 0;
        while ($processed++ < $limit) {
            $result = $this->processor->processOne();
            if ($result === 'idle') break;
            $output->writeln(match ($result) {
                'sent' => '<info>Un reversement Mobile Money a été confirmé.</info>',
                'failed' => '<comment>Le reversement a échoué; la réserve est libérée.</comment>',
                'pending' => '<comment>Reversement en attente de confirmation FedaPay.</comment>',
                default => '<error>Résultat ambigu; montant toujours réservé, intervention nécessaire sans renvoi aveugle.</error>',
            });
            if ($result === 'ambiguous') $hadFailure = true;
        }
        return $hadFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
