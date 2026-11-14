<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create-super-admin', description: 'Create a global Miksprit super-administrator account.')]
final class CreateSuperAdminCommand extends Command
{
    public function __construct(private readonly UserRepository $users, private readonly UserPasswordHasherInterface $hasher) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $emailQuestion = new Question('Email: ');
        $email = $helper->ask($input, $output, $emailQuestion);
        $passwordQuestion = new Question('Password (minimum 12 characters): ');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);
        $password = $helper->ask($input, $output, $passwordQuestion);
        if (!is_string($email) || strlen(trim($email)) > 190 || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false || !is_string($password) || strlen($password) < 12 || strlen($password) > 4096) {
            $output->writeln('<error>Valid email (at most 190 bytes) and a 12–4096 character password are required.</error>');
            return Command::FAILURE;
        }
        if ($this->users->findByEmail($email) !== null) {
            $output->writeln('<error>This email is already registered.</error>');
            return Command::FAILURE;
        }
        $user = User::create(bin2hex(random_bytes(16)), $email, 'pending', true);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->users->save($user);
        $output->writeln('<info>Super-administrator created.</info>');
        return Command::SUCCESS;
    }
}
