<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

use App\Service\Router\RouterManagementPort;

/** Creates a Hotspot user using an API sentence; all fields are sent as data words. */
final readonly class RouterOsHotspotProvisioner implements RouterManagementPort
{
    public function __construct(
        private RouterOsTransport $transport,
        private RouterOsConnection $connection,
        private RouterOsCredentials $credentials,
    ) {
    }

    public function createUser(string $username, string $password, string $profile, ?string $limitUptime = null, ?int $limitBytes = null, int $sharedUsers = 1): void
    {
        foreach (['username' => $username, 'password' => $password, 'profile' => $profile] as $field => $value) {
            if ($value === '' || strlen($value) > 128 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException(sprintf('Invalid RouterOS Hotspot %s.', $field));
            }
        }
        if (($limitBytes !== null && $limitBytes < 1) || $sharedUsers < 1 || $sharedUsers > 64) {
            throw new \InvalidArgumentException('Invalid RouterOS usage limit.');
        }
        if ($limitUptime !== null && ($limitUptime === '' || preg_match('/^(?=.*[0-9])(?:[0-9]+w)?(?:[0-9]+d)?(?:[0-9]+h)?(?:[0-9]+m)?(?:[0-9]+s)?$/', $limitUptime) !== 1)) {
            throw new \InvalidArgumentException('Invalid RouterOS limit-uptime duration.');
        }

        $words = ['/ip/hotspot/user/add', '=name='.$username, '=password='.$password, '=profile='.$profile];
        if ($limitUptime !== null) {
            $words[] = '=limit-uptime='.$limitUptime;
        }
        if ($limitBytes !== null) $words[] = '=limit-bytes-total='.$limitBytes;
        $words[] = '=shared-users='.$sharedUsers;
        $reply = $this->transport->execute($this->connection, $this->credentials, new RouterOsSentence($words));
        if ($reply->status !== RouterOsReplyStatus::DONE) {
            // Do not include provider text or any voucher credentials in exception messages.
            throw new RouterOsCommandRejected('RouterOS rejected Hotspot user provisioning.'.($reply->safeErrorCode ? ' ['.$reply->safeErrorCode.']' : ''));
        }
    }

    public function revokeUser(string $username): bool
    {
        self::assertIdentifier($username, 'username');
        $lookup = $this->transport->execute(
            $this->connection,
            $this->credentials,
            new RouterOsSentence(['/ip/hotspot/user/print', '?name='.$username, '=.proplist=.id,name']),
        );
        $matchingIds = [];
        foreach ($lookup->records as $record) {
            if (($record['name'] ?? null) === $username && isset($record['.id']) && preg_match('/^\*[A-Fa-f0-9]+$/D', $record['.id']) === 1) {
                $matchingIds[] = $record['.id'];
            }
        }
        if ($matchingIds === []) {
            return false;
        }
        if (count($matchingIds) !== 1) {
            throw new RouterOsException('RouterOS returned an ambiguous Hotspot user match.');
        }
        $removed = $this->transport->execute(
            $this->connection,
            $this->credentials,
            new RouterOsSentence(['/ip/hotspot/user/remove', '=.id='.$matchingIds[0]]),
        );
        if ($removed->status !== RouterOsReplyStatus::DONE) {
            throw new RouterOsCommandRejected('RouterOS rejected Hotspot user revocation.');
        }

        return true;
    }

    public function health(): array
    {
        $resource = $this->transport->execute($this->connection, $this->credentials, new RouterOsSentence(['/system/resource/print', '=.proplist=version,uptime']));
        $identity = $this->transport->execute($this->connection, $this->credentials, new RouterOsSentence(['/system/identity/print', '=.proplist=name']));
        $resourceRow = $resource->records[0] ?? [];
        $identityRow = $identity->records[0] ?? [];
        $version = $resourceRow['version'] ?? null;
        $uptime = $resourceRow['uptime'] ?? null;
        $name = $identityRow['name'] ?? null;
        if (!is_string($version) || !is_string($uptime) || !is_string($name) || $version === '' || $uptime === '' || $name === '') {
            throw new RouterOsTransportFailure();
        }
        return ['version' => substr($version, 0, 32), 'uptime' => substr($uptime, 0, 64), 'identity' => substr($name, 0, 120)];
    }

    public function activeSessions(): array
    {
        $reply = $this->transport->execute($this->connection, $this->credentials, new RouterOsSentence(['/ip/hotspot/active/print', '=.proplist=.id,user,address,mac-address,uptime,bytes-in,bytes-out']));
        $sessions = [];
        foreach ($reply->records as $record) {
            $id = $record['.id'] ?? null;
            $username = $record['user'] ?? null;
            if (!is_string($id) || preg_match('/^\*[A-Fa-f0-9]+$/D', $id) !== 1 || !is_string($username) || $username === '') {
                continue;
            }
            $sessions[] = [
                'externalId' => $id,
                'username' => $username,
                'address' => self::optionalString($record['address'] ?? null),
                'macAddress' => self::optionalString($record['mac-address'] ?? null),
                'uptime' => self::optionalString($record['uptime'] ?? null),
                'bytesIn' => self::optionalCounter($record['bytes-in'] ?? null),
                'bytesOut' => self::optionalCounter($record['bytes-out'] ?? null),
            ];
        }
        return $sessions;
    }

    public function disconnectSession(string $externalId): bool
    {
        if (preg_match('/^\*[A-Fa-f0-9]+$/D', $externalId) !== 1) {
            throw new \InvalidArgumentException('Invalid RouterOS active-session identifier.');
        }
        $sessionExists = false;
        foreach ($this->activeSessions() as $session) {
            if ($session['externalId'] === $externalId) {
                $sessionExists = true;
                break;
            }
        }
        if (!$sessionExists) {
            return false;
        }
        $reply = $this->transport->execute($this->connection, $this->credentials, new RouterOsSentence(['/ip/hotspot/active/remove', '=.id='.$externalId]));
        if ($reply->status !== RouterOsReplyStatus::DONE) {
            throw new RouterOsCommandRejected('RouterOS rejected active-session disconnection.');
        }
        return true;
    }

    private static function assertIdentifier(string $value, string $field): void
    {
        if ($value === '' || strlen($value) > 128 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('Invalid RouterOS %s.', $field));
        }
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 160 ? $value : null;
    }

    private static function optionalCounter(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) === 1 ? $value : null;
    }
}
