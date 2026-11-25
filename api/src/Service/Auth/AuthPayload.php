<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;

final readonly class AuthPayload
{
    /** @return array{user: array{id: string, email: string}, availableApps: list<string>, defaultApp: ?string} */
    public function for(User $user): array
    {
        // Every authenticated user can open the client app to create their first
        // organization. Tenant data remains protected by membership checks in the API.
        $apps = ['client'];
        if ($user->isSuperAdmin()) {
            $apps[] = 'admin';
        }
        return ['user' => ['id' => $user->id(), 'email' => $user->email()], 'availableApps' => $apps, 'defaultApp' => count($apps) === 1 ? $apps[0] : null];
    }
}
