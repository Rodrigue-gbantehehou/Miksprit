<?php

declare(strict_types=1);

namespace App\Entity;

enum AccountLinkPurpose: string
{
    case PasswordReset = 'password_reset';
    case Invitation = 'invitation';
    case EmailVerification = 'email_verification';
}
