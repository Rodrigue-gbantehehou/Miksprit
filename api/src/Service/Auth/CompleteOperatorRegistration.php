<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\PendingOperatorRegistration;
use App\Entity\TenantId;
use App\Entity\TenantRole;
use App\Entity\User;
use App\Repository\PendingOperatorRegistrationRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CompleteOperatorRegistration
{
    public function __construct(
        private PendingOperatorRegistrationRepository $registrations,
        private UserRepository $users,
        private EntityManagerInterface $entities,
    ) {
    }

    public function complete(string $rawToken): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $rawToken) !== 1) {
            return false;
        }
        $now = new \DateTimeImmutable();
        $registration = $this->registrations->findUsable(hash('sha256', $rawToken), $now);
        if ($registration === null) {
            return false;
        }

        try {
            return $this->registrations->consumeAtomically($registration, $now,
                function (PendingOperatorRegistration $pending): void {
                    if ($this->users->findByEmail($pending->email) !== null
                        || $this->entities->getRepository(Organization::class)->findOneBy(['slug' => $pending->organizationSlug]) !== null) {
                        throw new \DomainException('Registration details are no longer available.');
                    }
                    $user = User::create(bin2hex(random_bytes(16)), $pending->email, $pending->passwordHash);
                    $user->markEmailVerified();
                    $tenantId = new TenantId(bin2hex(random_bytes(16)));
                    $organization = Organization::create($tenantId, $pending->organizationName, $pending->organizationSlug);
                    $membership = Membership::owner(bin2hex(random_bytes(16)), $tenantId, $user->id());
                    $this->entities->persist($user);
                    $this->entities->persist($organization);
                    $this->entities->persist($membership);
                });
        } catch (UniqueConstraintViolationException|\DomainException) {
            return false;
        }
    }
}
