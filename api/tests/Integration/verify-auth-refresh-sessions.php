<?php

declare(strict_types=1);

use App\Service\Auth\RefreshSessionStore;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$connection->executeStatement('CREATE TABLE auth_refresh_sessions (id VARCHAR(32) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, absolute_expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL)');
$connection->executeStatement('CREATE TABLE auth_refresh_tokens (token_hash CHAR(64) PRIMARY KEY, session_id VARCHAR(32) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME NULL)');
$store = new RefreshSessionStore($connection, str_repeat('ci-refresh-proof-secret-', 2));
$session = $store->create('user_refresh_01');
$rotated = $store->rotate($session['raw']);
if ($rotated === null || $rotated['user_id'] !== 'user_refresh_01' || $rotated['raw'] === $session['raw']) {
    throw new RuntimeException('A valid refresh token was not rotated.');
}
$duplicate = $store->rotate($session['raw']);
if ($duplicate === null || $duplicate['raw'] !== $rotated['raw']) {
    throw new RuntimeException('A concurrent refresh did not return the same rotated token.');
}
$next = $store->rotate($rotated['raw']);
if ($next === null || $next['raw'] === $rotated['raw']) {
    throw new RuntimeException('The refresh session did not continue after an idempotent retry.');
}
$latestDuplicate = $store->rotate($session['raw']);
if ($latestDuplicate === null || $latestDuplicate['raw'] !== $next['raw']) {
    throw new RuntimeException('A delayed concurrent response did not recover the latest successor token.');
}
$connection->executeStatement("UPDATE auth_refresh_tokens SET consumed_at = datetime('now', '-2 minutes') WHERE token_hash = ?", [hash('sha256', $session['raw'])]);
if ($store->rotate($session['raw']) !== null || $store->rotate($next['raw']) !== null) {
    throw new RuntimeException('Reuse outside the grace window did not revoke its session family.');
}
$store->revokeUser('user_refresh_01');
$other = $store->create('user_refresh_02');
$store->revokeUser('user_refresh_02');
if ($store->rotate($other['raw']) !== null) {
    throw new RuntimeException('User-wide revocation did not invalidate the session.');
}

$expired = $store->create('user_refresh_03');
$connection->executeStatement("UPDATE auth_refresh_sessions SET absolute_expires_at = datetime('now', '-1 day') WHERE id = (SELECT session_id FROM auth_refresh_tokens WHERE token_hash = ?)", [hash('sha256', $expired['raw'])]);
$pruned = $store->pruneExpiredSessions();
$remaining = (int) $connection->fetchOne('SELECT COUNT(*) FROM auth_refresh_tokens WHERE token_hash = ?', [hash('sha256', $expired['raw'])]);
if ($pruned < 1 || $remaining !== 0) {
    throw new RuntimeException(sprintf('Expired refresh-session pruning failed (sessions=%d, tokens=%d).', $pruned, $remaining));
}

$active = $store->create('user_refresh_04');
$activeNext = $store->rotate($active['raw']);
if ($activeNext === null) throw new RuntimeException('Could not prepare the active-session pruning proof.');
$connection->executeStatement('UPDATE auth_refresh_tokens SET expires_at = datetime(\'now\', \'-1 day\') WHERE token_hash = ?', [hash('sha256', $active['raw'])]);
$store->pruneExpiredSessions();
$activeSessionId = $connection->fetchOne('SELECT session_id FROM auth_refresh_tokens WHERE token_hash = ?', [hash('sha256', $activeNext['raw'])]);
if (!is_string($activeSessionId)) throw new RuntimeException('Pruning removed a session with a valid active refresh token.');
$connection->executeStatement('UPDATE auth_refresh_tokens SET expires_at = datetime(\'now\', \'-1 day\') WHERE token_hash = ?', [hash('sha256', $activeNext['raw'])]);
$store->pruneExpiredSessions();
if ((int) $connection->fetchOne('SELECT COUNT(*) FROM auth_refresh_sessions WHERE id = ?', [$activeSessionId]) !== 0) {
    throw new RuntimeException('A session without a valid idle refresh token was not pruned.');
}

echo "Refresh-token rotation and revocation proof passed.\n";
