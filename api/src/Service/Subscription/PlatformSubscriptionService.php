<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\PaymentState;
use App\Entity\TenantContext;
use App\Entity\TenantPermission;
use App\Entity\User;
use App\Entity\VerifiedPaymentStatus;
use App\Repository\UserRepository;
use App\Service\Payment\PaymentCustomer;
use App\Service\Payment\PaymentIntent;
use App\Service\Payment\PaymentProviderRegistry;
use App\Service\Provider\Payment\CheckoutTokenUnavailable;
use App\Service\Provider\Payment\PaymentProviderUnavailable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Platform subscription billing is separate from tenant ticket sales and their payment ledger. */
final readonly class PlatformSubscriptionService
{
    public function __construct(
        private Connection $connection,
        private UserRepository $users,
        private PaymentProviderRegistry $providers,
        #[Autowire('%env(default:empty_secret:PLATFORM_SUBSCRIPTION_PROVIDER)%')]
        private string $providerCode,
        #[Autowire('%env(default:empty_secret:PLATFORM_SUBSCRIPTION_RETURN_URL)%')]
        private string $returnUrl,
    ) {}

    /** @return array{billingEnabled:bool,offers:list<array<string,mixed>>,subscription:?array<string,mixed>,payments:list<array<string,mixed>>} */
    public function ownerSummary(TenantContext $tenant): array
    {
        $this->assertPermission($tenant);
        $offers = $this->connection->fetchAllAssociative(
            'SELECT id, code, name, provider, amount_minor_units, interval_days, grace_days, expired_access_policy, active
             FROM platform_subscription_offers WHERE active = 1 ORDER BY amount_minor_units, name',
        );
        $subscription = $this->connection->fetchAssociative(
            'SELECT s.id, s.status, s.offer_id, s.offer_name_snapshot, s.amount_minor_units_snapshot, s.interval_days_snapshot,
                    s.started_at, s.current_period_start, s.current_period_end, s.next_due_at, s.grace_days_snapshot,
                    s.expired_access_policy, s.updated_at
             FROM platform_subscriptions s WHERE s.tenant_id = ?',
            [$tenant->tenantId->value],
        );
        if ($subscription !== false) $subscription = $this->presentSubscription($subscription);
        $payments = $this->connection->fetchAllAssociative(
            'SELECT id, provider, merchant_reference, provider_reference, amount_minor_units, currency, state, created_at, paid_at
             FROM platform_subscription_payments WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 50',
            [$tenant->tenantId->value],
        );

        return [
            'billingEnabled' => $this->billingEnabled(),
            'offers' => array_map(self::presentOffer(...), $offers),
            'subscription' => $subscription === false ? null : $subscription,
            'payments' => array_map(self::presentPayment(...), $payments),
        ];
    }

    /** @return array{checkoutUrl:?string,paymentId:string,state:string} */
    public function startCheckout(TenantContext $tenant, string $offerId): array
    {
        $this->assertPermission($tenant);
        if (!$this->billingEnabled()) throw new PaymentProviderUnavailable('Le paiement de l’abonnement Miksprit n’est pas configuré.');
        if (trim($offerId) === '' || strlen($offerId) > 80) throw new \InvalidArgumentException('Offre d’abonnement invalide.');
        $offer = $this->connection->fetchAssociative('SELECT * FROM platform_subscription_offers WHERE id = ? AND active = 1', [$offerId]);
        if ($offer === false || $offer['provider'] !== $this->providerCode) throw new \DomainException('Cette offre n’est pas disponible.');

        $pending = $this->connection->fetchAssociative(
            "SELECT id, offer_id, merchant_reference, provider_reference, state, checkout_url FROM platform_subscription_payments WHERE tenant_id = ? AND state IN ('initiated', 'pending', 'needs_review') ORDER BY created_at DESC LIMIT 1",
            [$tenant->tenantId->value],
        );
        if ($pending !== false) {
            if ($pending['offer_id'] !== $offerId) throw new \DomainException('Terminez ou rapprochez le paiement d’abonnement en cours avant de choisir une autre offre.');
            if ($pending['state'] === 'needs_review' || $pending['provider_reference'] === null) {
                throw new \DomainException('Un paiement d’abonnement doit être rapproché avant une nouvelle tentative.');
            }
            $session = $this->providers->resumeCheckout($this->providerCode, (string) $pending['provider_reference'], 'subscription:'.$pending['id']);
            $this->connection->update('platform_subscription_payments', ['checkout_url' => $session->checkoutUrl], ['id' => $pending['id']]);
            return ['checkoutUrl' => $session->checkoutUrl, 'paymentId' => (string) $pending['id'], 'state' => (string) $pending['state']];
        }

        $owner = $this->users->findById($tenant->actorId);
        if (!$owner instanceof User) throw new \DomainException('Compte utilisateur introuvable.');
        $subscription = $this->connection->fetchAssociative('SELECT id, current_period_end, status FROM platform_subscriptions WHERE tenant_id = ?', [$tenant->tenantId->value]);
        if ($subscription !== false && in_array($subscription['status'], ['suspended', 'canceled'], true)) {
            throw new \DomainException('Cette souscription doit être réactivée par le support Miksprit.');
        }

        $now = new \DateTimeImmutable();
        $subscriptionId = $subscription === false ? bin2hex(random_bytes(16)) : (string) $subscription['id'];
        $paymentId = bin2hex(random_bytes(16));
        $merchantReference = 'miksprit-sub-'.bin2hex(random_bytes(16));
        $nowString = $now->format('Y-m-d H:i:s');
        $this->connection->transactional(function (Connection $connection) use ($subscription, $subscriptionId, $tenant, $offer, $nowString, $paymentId, $merchantReference): void {
            if ($subscription === false) {
                $connection->insert('platform_subscriptions', [
                    'id' => $subscriptionId, 'tenant_id' => $tenant->tenantId->value, 'offer_id' => $offer['id'],
                    'status' => 'pending', 'offer_name_snapshot' => $offer['name'], 'amount_minor_units_snapshot' => $offer['amount_minor_units'],
                    'interval_days_snapshot' => $offer['interval_days'], 'grace_days_snapshot' => $offer['grace_days'],
                    'expired_access_policy' => $offer['expired_access_policy'], 'started_at' => null,
                    'current_period_start' => null, 'current_period_end' => null, 'next_due_at' => null,
                    'created_at' => $nowString, 'updated_at' => $nowString,
                ]);
            }
            $connection->insert('platform_subscription_payments', [
                'id' => $paymentId, 'tenant_id' => $tenant->tenantId->value, 'subscription_id' => $subscriptionId,
                'offer_id' => $offer['id'], 'provider' => $offer['provider'], 'merchant_reference' => $merchantReference,
                'provider_reference' => null, 'offer_name_snapshot' => $offer['name'], 'amount_minor_units' => $offer['amount_minor_units'],
                'interval_days' => $offer['interval_days'], 'grace_days' => $offer['grace_days'], 'expired_access_policy' => $offer['expired_access_policy'],
                'currency' => 'XOF', 'state' => 'initiated', 'open_key' => $tenant->tenantId->value, 'checkout_url' => null, 'created_at' => $nowString,
                'updated_at' => $nowString, 'paid_at' => null,
            ]);
        });

        $intent = new PaymentIntent($merchantReference, (int) $offer['amount_minor_units'], 'XOF', 'Abonnement Miksprit - '.(string) $offer['name'], $this->returnUrl,
            new PaymentCustomer($owner->email()));
        try {
            $session = $this->providers->checkout($this->providerCode, $intent, 'subscription:'.$paymentId);
        } catch (CheckoutTokenUnavailable $exception) {
            $this->connection->executeStatement("UPDATE platform_subscription_payments SET provider_reference = ?, state = 'pending', updated_at = ? WHERE id = ?", [$exception->providerReference, $nowString, $paymentId]);
            return ['checkoutUrl' => null, 'paymentId' => $paymentId, 'state' => 'pending'];
        } catch (PaymentProviderUnavailable $exception) {
            $this->connection->executeStatement("UPDATE platform_subscription_payments SET state = 'needs_review', updated_at = ? WHERE id = ?", [$nowString, $paymentId]);
            throw $exception;
        }

        $this->connection->executeStatement(
            "UPDATE platform_subscription_payments SET provider_reference = ?, checkout_url = ?, state = 'pending', updated_at = ? WHERE id = ?",
            [$session->providerReference, $session->checkoutUrl, $nowString, $paymentId],
        );
        return ['checkoutUrl' => $session->checkoutUrl, 'paymentId' => $paymentId, 'state' => 'pending'];
    }

    public function applyVerifiedPayment(VerifiedPaymentStatus $status): void
    {
        $this->connection->transactional(function (Connection $connection) use ($status): void {
            $payment = $connection->fetchAssociative(
                'SELECT id, tenant_id, subscription_id, offer_id, provider, merchant_reference, provider_reference, offer_name_snapshot, amount_minor_units, interval_days, grace_days, expired_access_policy, currency, state
                 FROM platform_subscription_payments WHERE provider = ? AND provider_reference = ?',
                [$status->provider, $status->providerReference],
            );
            if ($payment === false) throw new \DomainException('Platform subscription payment was not found.');
            if ($payment['merchant_reference'] !== $status->merchantReference || (int) $payment['amount_minor_units'] !== $status->amount->minorUnits || $payment['currency'] !== $status->currency) {
                throw new \DomainException('Verified subscription payment does not match its recorded offer.');
            }
            if ($payment['state'] === PaymentState::Succeeded->value) return;
            $observedAt = $status->observedAt->format('Y-m-d H:i:s');
            $connection->executeStatement('UPDATE platform_subscription_payments SET state = ?, open_key = ?, updated_at = ?, paid_at = ? WHERE id = ?',
                [$status->state->value, in_array($status->state, [PaymentState::Failed, PaymentState::Expired, PaymentState::Succeeded], true) ? null : $payment['tenant_id'], $observedAt, $status->state === PaymentState::Succeeded ? $observedAt : null, $payment['id']]);
            if ($status->state !== PaymentState::Succeeded) return;

            $subscription = $connection->fetchAssociative('SELECT current_period_end FROM platform_subscriptions WHERE id = ? AND tenant_id = ?', [$payment['subscription_id'], $payment['tenant_id']]);
            if ($subscription === false) throw new \DomainException('Platform subscription record was not found.');
            $now = $status->observedAt;
            $existingEnd = $subscription['current_period_end'] === null ? null : new \DateTimeImmutable((string) $subscription['current_period_end']);
            $periodStart = $existingEnd !== null && $existingEnd > $now ? $existingEnd : $now;
            $periodEnd = $periodStart->add(new \DateInterval('P'.(int) $payment['interval_days'].'D'));
            $connection->executeStatement(
                "UPDATE platform_subscriptions SET offer_id = ?, status = 'active', offer_name_snapshot = ?, amount_minor_units_snapshot = ?, interval_days_snapshot = ?, grace_days_snapshot = ?, expired_access_policy = ?, started_at = COALESCE(started_at, ?), current_period_start = ?, current_period_end = ?, next_due_at = ?, updated_at = ? WHERE id = ? AND tenant_id = ?",
                [$payment['offer_id'], $payment['offer_name_snapshot'], $payment['amount_minor_units'], $payment['interval_days'], $payment['grace_days'], $payment['expired_access_policy'], $now->format('Y-m-d H:i:s'), $periodStart->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $payment['subscription_id'], $payment['tenant_id']],
            );
        });
    }

    /** @return list<array<string,mixed>> */
    public function adminOffers(): array
    {
        return array_map(self::presentOffer(...), $this->connection->fetchAllAssociative('SELECT * FROM platform_subscription_offers ORDER BY created_at DESC'));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function saveOffer(?string $offerId, array $data): array
    {
        $name = self::requiredText($data['name'] ?? null, 120);
        $code = self::requiredText($data['code'] ?? null, 80);
        if (preg_match('/^[a-z0-9](?:[a-z0-9_-]{0,78}[a-z0-9])?$/D', $code) !== 1) throw new \InvalidArgumentException('Le code doit être stable et utiliser des lettres minuscules, chiffres, tirets ou underscores.');
        $provider = is_string($data['provider'] ?? null) ? strtolower(trim($data['provider'])) : '';
        if ($provider === '' || !in_array($provider, $this->providers->codes(), true)) throw new \InvalidArgumentException('Fournisseur de paiement de plateforme inconnu.');
        $amount = self::positiveInt($data['amountXof'] ?? null);
        $interval = self::positiveInt($data['intervalDays'] ?? null);
        if ($interval > 3650) throw new \InvalidArgumentException('La période ne peut pas dépasser 3 650 jours.');
        $graceDays = self::optionalInt($data['graceDays'] ?? null, 0, 365);
        $policy = is_string($data['expiredAccessPolicy'] ?? null) ? $data['expiredAccessPolicy'] : '';
        if ($policy !== 'read_only') throw new \InvalidArgumentException('La politique actuellement prise en charge conserve les historiques en lecture seule après expiration.');
        if (!is_bool($data['active'] ?? null)) throw new \InvalidArgumentException('L’état actif de l’offre doit être indiqué.');
        if ($data['active'] && $provider !== $this->providerCode) throw new \DomainException('L’offre active doit utiliser le fournisseur configuré pour les abonnements Miksprit.');
        if ($data['active'] && !$this->billingEnabled()) throw new \DomainException('Configurez le fournisseur de paiement central avant d’activer une offre.');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $fields = ['code' => $code, 'name' => $name, 'provider' => $provider, 'amount_minor_units' => $amount, 'interval_days' => $interval,
            'grace_days' => $graceDays, 'expired_access_policy' => $policy, 'active' => $data['active'] ? 1 : 0, 'updated_at' => $now];
        if ($offerId === null) {
            $offerId = bin2hex(random_bytes(16));
            $this->connection->insert('platform_subscription_offers', ['id' => $offerId, ...$fields, 'created_at' => $now]);
        } else {
            $exists = $this->connection->fetchOne('SELECT 1 FROM platform_subscription_offers WHERE id = ?', [$offerId]);
            if (!$exists) throw new \DomainException('Offre introuvable.');
            $this->connection->update('platform_subscription_offers', $fields, ['id' => $offerId]);
        }
        $offer = $this->connection->fetchAssociative('SELECT * FROM platform_subscription_offers WHERE id = ?', [$offerId]);
        return is_array($offer) ? self::presentOffer($offer) : throw new \RuntimeException('Offer persistence failed.');
    }

    /** @return list<array<string,mixed>> */
    public function adminSubscriptions(): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'tenantId' => (string) $row['tenant_id'], 'organization' => (string) $row['organization_name'],
            'status' => (string) $row['status'], 'offer' => (string) $row['offer_name_snapshot'], 'amountXof' => (int) $row['amount_minor_units_snapshot'],
            'currentPeriodEnd' => $row['current_period_end'] === null ? null : (string) $row['current_period_end'], 'nextDueAt' => $row['next_due_at'] === null ? null : (string) $row['next_due_at'],
        ], $this->connection->fetchAllAssociative('SELECT s.*, o.name AS organization_name FROM platform_subscriptions s INNER JOIN organizations o ON o.tenant_id = s.tenant_id ORDER BY s.updated_at DESC LIMIT 500'));
    }

    public function setAdminStatus(string $subscriptionId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended', 'canceled'], true)) throw new \InvalidArgumentException('État de souscription non pris en charge.');
        $updated = $this->connection->executeStatement('UPDATE platform_subscriptions SET status = ?, updated_at = ? WHERE id = ?', [$status, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $subscriptionId]);
        if ($updated !== 1) throw new \DomainException('Souscription introuvable.');
    }

    public function refreshExpiredSubscriptions(int $limit = 500): int
    {
        if ($limit < 1 || $limit > 5000) throw new \InvalidArgumentException('Subscription refresh limit is invalid.');
        $now = new \DateTimeImmutable();
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, status, current_period_end, grace_days_snapshot FROM platform_subscriptions WHERE status IN ('active', 'past_due') AND current_period_end <= ? ORDER BY current_period_end LIMIT ".$limit,
            [$now->format('Y-m-d H:i:s')],
        );
        $updated = 0;
        foreach ($rows as $row) {
            $end = new \DateTimeImmutable((string) $row['current_period_end']);
            $status = $end->add(new \DateInterval('P'.(int) $row['grace_days_snapshot'].'D')) > $now ? 'past_due' : 'expired';
            if ($status !== $row['status']) {
                $updated += $this->connection->executeStatement('UPDATE platform_subscriptions SET status = ?, updated_at = ? WHERE id = ? AND status = ?',
                    [$status, $now->format('Y-m-d H:i:s'), $row['id'], $row['status']]);
            }
        }
        return $updated;
    }

    public function isPaymentReference(string $provider, string $providerReference): bool
    {
        return (bool) $this->connection->fetchOne('SELECT 1 FROM platform_subscription_payments WHERE provider = ? AND provider_reference = ?', [$provider, $providerReference]);
    }

    public function billingEnabled(): bool
    {
        return $this->providerCode !== '' && in_array($this->providerCode, $this->providers->codes(), true) && $this->providers->canCheckout($this->providerCode);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function presentSubscription(array $row): array
    {
        $status = (string) $row['status'];
        if (in_array($status, ['active', 'past_due'], true) && $row['current_period_end'] !== null) {
            $end = new \DateTimeImmutable((string) $row['current_period_end']);
            if ($end <= new \DateTimeImmutable()) {
                $graceEnd = $end->add(new \DateInterval('P'.(int) $row['grace_days_snapshot'].'D'));
                $status = $graceEnd > new \DateTimeImmutable() ? 'past_due' : 'expired';
            }
        }
        return [
            'id' => (string) $row['id'], 'status' => $status, 'offerId' => (string) $row['offer_id'],
            'offerName' => (string) $row['offer_name_snapshot'], 'amountXof' => (int) $row['amount_minor_units_snapshot'],
            'intervalDays' => (int) $row['interval_days_snapshot'], 'startedAt' => $row['started_at'] === null ? null : (string) $row['started_at'],
            'currentPeriodStart' => $row['current_period_start'] === null ? null : (string) $row['current_period_start'],
            'currentPeriodEnd' => $row['current_period_end'] === null ? null : (string) $row['current_period_end'],
            'nextDueAt' => $row['next_due_at'] === null ? null : (string) $row['next_due_at'], 'updatedAt' => (string) $row['updated_at'],
            'expiredAccessPolicy' => (string) $row['expired_access_policy'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function presentOffer(array $row): array
    {
        return ['id' => (string) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'], 'provider' => (string) $row['provider'],
            'amountXof' => (int) $row['amount_minor_units'], 'intervalDays' => (int) $row['interval_days'], 'graceDays' => (int) $row['grace_days'],
            'expiredAccessPolicy' => (string) $row['expired_access_policy'], 'active' => (bool) $row['active']];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function presentPayment(array $row): array
    {
        return ['id' => (string) $row['id'], 'provider' => (string) $row['provider'], 'reference' => (string) $row['merchant_reference'],
            'providerReference' => $row['provider_reference'] === null ? null : (string) $row['provider_reference'],
            'amountXof' => (int) $row['amount_minor_units'], 'currency' => (string) $row['currency'], 'status' => (string) $row['state'],
            'createdAt' => (string) $row['created_at'], 'paidAt' => $row['paid_at'] === null ? null : (string) $row['paid_at']];
    }

    private function assertPermission(TenantContext $tenant): void
    {
        if (!$tenant->allows(TenantPermission::ManageSubscription)) throw new \DomainException('Access denied for this tenant operation.');
    }

    private static function requiredText(mixed $value, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) throw new \InvalidArgumentException('Un champ texte obligatoire est invalide.');
        return trim($value);
    }

    private static function positiveInt(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 2_147_483_647) throw new \InvalidArgumentException('Un entier positif est obligatoire.');
        return $value;
    }

    private static function optionalInt(mixed $value, int $min, int $max): int
    {
        if ($value === null || $value === '') return 0;
        if (!is_int($value) || $value < $min || $value > $max) throw new \InvalidArgumentException('La durée de grâce doit être comprise entre 0 et 365 jours.');
        return $value;
    }
}
