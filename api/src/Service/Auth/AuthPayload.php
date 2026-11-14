<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\MembershipRepository;

final readonly class AuthPayload
{
    public function __construct(private MembershipRepository $memberships) {}

    /** @return array{user: array{id: string, email: string}, availableApps: list<string>, defaultApp: ?string} */
    public function for(User $user): array
    {
        $apps = [];
        if ($this->memberships->findActiveForActor($user->id()) !== []) {
            $apps[] = 'client';
        }
        if ($user->isSuperAdmin()) {
            $apps[] = 'admin';
        }
        return ['user' => ['id' => $user->id(), 'email' => $user->email()], 'availableApps' => $apps, 'defaultApp' => count($apps) === 1 ? $apps[0] : null];
    }
}
