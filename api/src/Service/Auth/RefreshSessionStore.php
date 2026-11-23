<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RefreshSessionStore
{
    public const IDLE_TTL = 1209600; // 14 days
    public const ABSOLUTE_TTL = 2592000; // 30 days
    private const ROTATION_GRACE = 30;

    public function __construct(
        private Connection $connection,
        #[Autowire('%env(APP_SECRET)%')] private string $appSecret,
    ) {
        if (strlen($this->appSecret) < 32) throw new \LogicException('APP_SECRET must contain at least 32 bytes for refresh-token rotation.');
    }

    /** @return array{raw: string, expires: int} */
    public function create(string $userId): array
    {
        $now = time();
        $sessionId = bin2hex(random_bytes(16));
        $raw = self::newToken();
        $expires = $now + self::ABSOLUTE_TTL;
        $this->connection->insert('auth_refresh_sessions', [
            'id' => $sessionId, 'user_id' => $userId,
            'absolute_expires_at' => gmdate('Y-m-d H:i:s', $expires),
            'revoked_at' => null, 'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
        $this->connection->insert('auth_refresh_tokens', [
            'token_hash' => hash('sha256', $raw), 'session_id' => $sessionId,
            'expires_at' => gmdate('Y-m-d H:i:s', min($expires, $now + self::IDLE_TTL)),
            'consumed_at' => null,
        ]);
        return ['raw' => $raw, 'expires' => min($expires, $now + self::IDLE_TTL)];
    }

    /** @return array{user_id: string, raw: string, expires: int}|null */
    public function rotate(string $raw): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $raw) !== 1) return null;
        $hash = hash('sha256', $raw);
        return $this->connection->transactional(function (Connection $connection) use ($hash, $raw): ?array {
            $platform = $connection->getDatabasePlatform();
            $suffix = $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
            $token = $connection->fetchAssociative('SELECT t.session_id, t.expires_at, t.consumed_at, s.user_id, s.absolute_expires_at, s.revoked_at FROM auth_refresh_tokens t JOIN auth_refresh_sessions s ON s.id = t.session_id WHERE t.token_hash = ?'.$suffix, [$hash]);
            if ($token === false) return null;
            $now = time();
            if ($token['consumed_at'] !== null) {
                $consumedAt = strtotime((string) $token['consumed_at'].' UTC');
                if ($consumedAt !== false && $now - $consumedAt <= self::ROTATION_GRACE
                    && $token['revoked_at'] === null
                    && strtotime((string) $token['absolute_expires_at'].' UTC') > $now) {
                    // Concurrent tabs/origins recover the current successor during a short grace window.
                    // Deterministic HMAC derivation avoids storing any raw refresh token in the database.
                    $previous = $raw;
                    for ($generation = 0; $generation < 8; $generation++) {
                        $next = $this->successorToken((string) $token['session_id'], $previous);
                        $successor = $connection->fetchAssociative(
                            'SELECT expires_at, consumed_at FROM auth_refresh_tokens WHERE token_hash = ? AND session_id = ?',
                            [hash('sha256', $next), $token['session_id']],
                        );
                        if ($successor === false) break;
                        if ($successor['consumed_at'] === null) {
                            $expires = strtotime((string) $successor['expires_at'].' UTC');
                            if ($expires > $now) return ['user_id' => (string) $token['user_id'], 'raw' => $next, 'expires' => $expires];
                            break;
                        }
                        $nextConsumedAt = strtotime((string) $successor['consumed_at'].' UTC');
                        if ($nextConsumedAt === false || $now - $nextConsumedAt > self::ROTATION_GRACE) break;
                        $previous = $next;
                    }
                }
                // Reuse outside the grace window revokes the entire session family.
                $connection->update('auth_refresh_sessions', ['revoked_at' => gmdate('Y-m-d H:i:s', $now)], ['id' => $token['session_id'], 'revoked_at' => null]);
                return null;
            }
            if ($token['revoked_at'] !== null || strtotime((string) $token['expires_at'].' UTC') <= $now || strtotime((string) $token['absolute_expires_at'].' UTC') <= $now) {
                return null;
            }
            $connection->update('auth_refresh_tokens', ['consumed_at' => gmdate('Y-m-d H:i:s', $now)], ['token_hash' => $hash]);
            $next = $this->successorToken((string) $token['session_id'], $raw);
            $expires = min(strtotime((string) $token['absolute_expires_at'].' UTC'), $now + self::IDLE_TTL);
            $connection->insert('auth_refresh_tokens', [
                'token_hash' => hash('sha256', $next), 'session_id' => $token['session_id'],
                'expires_at' => gmdate('Y-m-d H:i:s', $expires), 'consumed_at' => null,
            ]);
            $this->purgeExpiredSessions($connection, $now);
            return ['user_id' => (string) $token['user_id'], 'raw' => $next, 'expires' => $expires];
        });
    }

    public function revoke(string $raw): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $raw) !== 1) return;
        $sessionId = $this->connection->fetchOne('SELECT session_id FROM auth_refresh_tokens WHERE token_hash = ?', [hash('sha256', $raw)]);
        if (is_string($sessionId)) $this->revokeSession($sessionId);
    }

    public function revokeUser(string $userId): void
    {
        $this->connection->executeStatement('UPDATE auth_refresh_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [gmdate('Y-m-d H:i:s'), $userId]);
    }

    public function pruneExpiredSessions(): int
    {
        return $this->purgeExpiredSessions($this->connection, time());
    }

    private function revokeSession(string $sessionId): void
    {
        $this->connection->update('auth_refresh_sessions', ['revoked_at' => gmdate('Y-m-d H:i:s')], ['id' => $sessionId, 'revoked_at' => null]);
    }

    private function successorToken(string $sessionId, string $previousToken): string
    {
        $bytes = hash_hmac('sha256', "miksprit-refresh-v1\0".$sessionId."\0".$previousToken, $this->appSecret, true);
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function purgeExpiredSessions(Connection $connection, int $now): int
    {
        $timestamp = gmdate('Y-m-d H:i:s', $now);
        $connection->executeStatement('DELETE FROM auth_refresh_tokens WHERE expires_at <= ?', [$timestamp]);
        $inactiveSession = '(s.absolute_expires_at <= ? OR (s.revoked_at IS NOT NULL AND s.revoked_at <= ?) OR NOT EXISTS (
            SELECT 1 FROM auth_refresh_tokens active_token
            WHERE active_token.session_id = s.id AND active_token.consumed_at IS NULL AND active_token.expires_at > ?
        ))';
        $inactiveParams = [$timestamp, $timestamp, $timestamp];
        $connection->executeStatement(
            'DELETE FROM auth_refresh_tokens WHERE session_id IN (SELECT s.id FROM auth_refresh_sessions s WHERE '.$inactiveSession.')',
            $inactiveParams,
        );
        $sessionIds = $connection->fetchFirstColumn('SELECT s.id FROM auth_refresh_sessions s WHERE '.$inactiveSession, $inactiveParams);
        foreach ($sessionIds as $sessionId) {
            $connection->delete('auth_refresh_sessions', ['id' => (string) $sessionId]);
        }
        return count($sessionIds);
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
