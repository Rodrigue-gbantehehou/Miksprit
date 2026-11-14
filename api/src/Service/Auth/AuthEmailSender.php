<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class AuthEmailSender
{
    public function __construct(private MailerInterface $mailer, private string $fromAddress, private string $fromName) {}

    public function send(string $recipient, string $subject, string $text): void
    {
        $email = (new Email())->from(new Address($this->fromAddress, $this->fromName))->to($recipient)->subject($subject)->text($text);
        $this->mailer->send($email);
    }
}
