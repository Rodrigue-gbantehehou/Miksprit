<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Auth\RefreshSessionStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'miksprit:auth:prune-refresh-sessions', description: 'Remove expired and revoked refresh sessions.')]
final class PruneRefreshSessionsCommand extends Command
{
    public function __construct(private readonly RefreshSessionStore $sessions)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->sessions->pruneExpiredSessions();
        $output->writeln(sprintf('Removed %d expired or revoked refresh session(s).', $deleted));
        return Command::SUCCESS;
    }
}
