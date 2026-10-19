<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * Structured JSON-lines logger with secret redaction.
 *
 * Log files are written outside the web root (storage/logs by default) and are
 * expected to be readable only by the service account.
 */
final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'security' => 25, 'warning' => 30, 'error' => 40];
    private const SENSITIVE_KEY = '/(pass(word)?|secret|token|authorization|cookie|app_key|device_key|signature|nonce|csrf)/i';

    private static string $dir = '';
    private static int $threshold = 20;

    public static function init(string $dir, string $level = 'info'): void
    {
        self::$dir = rtrim($dir, '/');
        self::$threshold = self::LEVELS[$level] ?? 20;
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0770, true);
        }
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $event, array $context = []): void
    {
        self::write('debug', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function security(string $event, array $context = []): void
    {
        self::write('security', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    /** @param array<string,mixed> $context */
    private static function write(string $level, string $event, array $context): void
    {
        if (self::$dir === '') {
            self::$dir = defined('DOORLOCK_ROOT') ? DOORLOCK_ROOT . '/storage/logs' : sys_get_temp_dir();
        }
        if ((self::LEVELS[$level] ?? 20) < self::$threshold) {
            return;
        }
        $record = [
            'ts' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'level' => $level,
            'event' => $event,
            'request_id' => RequestContext::requestId(),
            'user_id' => RequestContext::userId(),
            'ip' => RequestContext::ip(),
            'context' => self::redact($context),
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($line === false) {
            return;
        }
        $file = sprintf('%s/app-%s.log', self::$dir, gmdate('Y-m-d'));
        // Logging must never take the application down or leak to the client.
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Recursively remove secret-bearing fields from a context array.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function redact(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $clean[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::redact($value);
            } elseif ($value instanceof \Throwable) {
                $clean[$key] = [
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'code' => $value->getCode(),
                ];
            } elseif (is_object($value)) {
                $clean[$key] = '[object ' . get_class($value) . ']';
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, 500);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }
}

/** Per-request context (correlation id, actor, client ip) for logging. */
final class RequestContext
{
    private static ?string $requestId = null;
    private static ?int $userId = null;
    private static ?string $ip = null;

    public static function begin(?string $requestId, ?string $ip): void
    {
        self::$requestId = $requestId;
        self::$ip = $ip;
        self::$userId = null;
    }

    public static function setUserId(?int $userId): void
    {
        self::$userId = $userId;
    }

    public static function requestId(): ?string
    {
        return self::$requestId;
    }

    public static function userId(): ?int
    {
        return self::$userId;
    }

    public static function ip(): ?string
    {
        return self::$ip;
    }
}
