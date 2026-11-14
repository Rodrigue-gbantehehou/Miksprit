<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class AuthEmailSender
{
    public function __construct(private MailerInterface $mailer, private string $from) {}

    public function send(string $recipient, string $subject, string $text): void
    {
        $email = (new Email())->from(Address::create($this->from))->to($recipient)->subject($subject)->text($text);
        $this->mailer->send($email);
    }
}
