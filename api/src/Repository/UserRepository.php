<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;

interface UserRepository
{
    public function findByEmail(string $email): ?User;
    public function findById(string $id): ?User;
    public function save(User $user): void;
}
