<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Subscription\PlatformSubscriptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'miksprit:subscriptions:refresh', description: 'Update overdue and expired platform subscription states.')]
final class RefreshPlatformSubscriptionsCommand extends Command
{
    public function __construct(private readonly PlatformSubscriptionService $subscriptions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum subscriptions to inspect.', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1 || $limit > 5000) {
            $output->writeln('<error>Limit must be between 1 and 5000.</error>');
            return Command::INVALID;
        }

        $count = $this->subscriptions->refreshExpiredSubscriptions($limit);
        $output->writeln(sprintf('Updated %d platform subscription status(es).', $count));
        return Command::SUCCESS;
    }
}
