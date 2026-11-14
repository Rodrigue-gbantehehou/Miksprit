<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Minimal RouterOS API over certificate-verified TLS. Supports RouterOS login API (6.43+ and v7). */
#[AsAlias(RouterOsTransport::class)]
final class RouterOsApiTransport implements RouterOsTransport
{
    public function execute(RouterOsConnection $connection, RouterOsCredentials $credentials, RouterOsSentence $sentence): RouterOsReply
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $connection->host,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tls://'.$this->formatHost($connection->host).':'.$connection->port,
            $errno,
            $errstr,
            $connection->connectTimeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!is_resource($socket)) {
            // No command was sent; credentials are never included in the exception.
            throw new RouterOsUnavailable();
        }

        stream_set_timeout($socket, $connection->operationTimeoutSeconds);
        try {
            $this->writeSentence($socket, ['/login', '=name='.$credentials->username(), '=password='.$credentials->password()]);
            $login = $this->readReply($socket);
            if ($login->status !== RouterOsReplyStatus::DONE) {
                throw new RouterOsCommandRejected('RouterOS login was rejected.'.($login->safeErrorCode ? ' ['.$login->safeErrorCode.']' : ''));
            }

            $this->writeSentence($socket, $sentence->words);

            return $this->readReply($socket);
        } catch (RouterOsCommandRejected|RouterOsUnavailable|RouterOsTransportFailure $exception) {
            throw $exception;
        } catch (\Throwable) {
            // Once the command may have been written, any I/O/protocol failure is ambiguous.
            throw new RouterOsTransportFailure();
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket @param list<string> $words */
    private function writeSentence($socket, array $words): void
    {
        foreach ([...$words, ''] as $word) {
            $frame = self::encodeLength(strlen($word)).$word;
            $offset = 0;
            $length = strlen($frame);
            while ($offset < $length) {
                $written = @fwrite($socket, substr($frame, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new RouterOsTransportFailure();
                }
                $offset += $written;
            }
        }
    }

    /** @param resource $socket */
    private function readReply($socket): RouterOsReply
    {
        $records = [];
        $status = null;
        $safeErrorCode = null;
        while (true) {
            $words = $this->readSentence($socket);
            if ($words === []) {
                throw new RouterOsTransportFailure();
            }
            $kind = $words[0];
            $record = [];
            foreach (array_slice($words, 1) as $word) {
                if (preg_match('/^=([^=]+)=(.*)$/sD', $word, $matches) === 1) {
                    $record[$matches[1]] = $matches[2];
                }
            }
            if ($kind === '!re') {
                $records[] = $record;
                continue;
            }
            if ($kind === '!trap') {
                $status = RouterOsReplyStatus::TRAP;
                $category = $record['category'] ?? null;
                $safeErrorCode = is_string($category) && preg_match('/^[A-Z0-9_]{1,64}$/D', $category) === 1 ? $category : 'COMMAND_REJECTED';
                continue;
            }
            if ($kind === '!fatal') {
                throw new RouterOsTransportFailure();
            }
            if ($kind === '!done') {
                $status ??= RouterOsReplyStatus::DONE;
                break;
            }
            throw new RouterOsTransportFailure();
        }

        if ($status === RouterOsReplyStatus::TRAP) {
            throw new RouterOsCommandRejected('RouterOS rejected the API command.'.($safeErrorCode ? ' ['.$safeErrorCode.']' : ''));
        }

        return new RouterOsReply(RouterOsReplyStatus::DONE, null, $records);
    }

    /** @param resource $socket @return list<string> */
    private function readSentence($socket): array
    {
        $words = [];
        while (true) {
            $length = $this->readLength($socket);
            if ($length === 0) {
                return $words;
            }
            if ($length > 1_048_576) {
                throw new RouterOsTransportFailure();
            }
            $word = '';
            while (strlen($word) < $length) {
                $chunk = @fread($socket, $length - strlen($word));
                if (!is_string($chunk) || $chunk === '') {
                    throw new RouterOsTransportFailure();
                }
                $word .= $chunk;
            }
            $words[] = $word;
            if (count($words) > 1024) {
                throw new RouterOsTransportFailure();
            }
        }
    }

    /** @param resource $socket */
    private function readLength($socket): int
    {
        $first = ord($this->readExact($socket, 1));
        if (($first & 0x80) === 0) {
            return $first;
        }
        if (($first & 0xC0) === 0x80) {
            return (($first & 0x3F) << 8) | ord($this->readExact($socket, 1));
        }
        if (($first & 0xE0) === 0xC0) {
            $tail = unpack('n', $this->readExact($socket, 2))[1];
            return (($first & 0x1F) << 16) | $tail;
        }
        if (($first & 0xF0) === 0xE0) {
            return (($first & 0x0F) << 24) | unpack('N', "\0".$this->readExact($socket, 3))[1];
        }
        if ($first === 0xF0) {
            return unpack('N', $this->readExact($socket, 4))[1];
        }
        throw new RouterOsTransportFailure();
    }

    /** @param resource $socket */
    private function readExact($socket, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($socket, $length - strlen($buffer));
            if (!is_string($chunk) || $chunk === '') {
                throw new RouterOsTransportFailure();
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    private static function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            return pack('n', $length | 0x8000);
        }
        if ($length < 0x200000) {
            return chr(($length >> 16) | 0xC0).pack('n', $length & 0xFFFF);
        }
        if ($length < 0x10000000) {
            return chr(($length >> 24) | 0xE0).pack('N', $length & 0xFFFFFF);
        }
        return "\xF0".pack('N', $length);
    }

    private function formatHost(string $host): string
    {
        return str_contains($host, ':') ? '['.$host.']' : $host;
    }
}
