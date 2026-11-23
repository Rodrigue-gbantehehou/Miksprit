<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Router\SyncRouterHotspotSessions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'miksprit:routers:sync-sessions', description: 'Persist active RouterOS Hotspot session snapshots.')]
final class SyncRouterHotspotSessionsCommand extends Command
{
    public function __construct(private readonly SyncRouterHotspotSessions $synchronizer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of active routers to poll.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1 || $limit > 1000) {
            $io->error('The router session synchronization limit must be between 1 and 1000.');
            return Command::INVALID;
        }
        try {
            $summary = $this->synchronizer->execute($limit);
        } catch (\Throwable) {
            $io->error('Router session synchronization failed. Check the router and database health without logging credentials.');
            return Command::FAILURE;
        }
        $io->writeln(sprintf('Polled %d routers; %d unavailable; %d sessions started, %d updated, %d ended.',
            $summary['routers'], $summary['failed'], $summary['started'], $summary['updated'], $summary['ended']));
        return $summary['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
