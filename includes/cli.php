<?php

declare(strict_types=1);

namespace DoorLock;

/** Minimal CLI argument handling (works with native PHP and PHP.wasm). */
final class Cli
{
    /** @return array<int,string> */
    public static function args(): array
    {
        global $argv;
        if (isset($argv) && is_array($argv) && count($argv) > 1) {
            return array_values(array_slice($argv, 1));
        }
        $encoded = getenv('DOORLOCK_CLI_ARGS');
        if (is_string($encoded) && $encoded !== '') {
            $decoded = json_decode($encoded, true);
            if (is_array($decoded)) {
                return array_values(array_map('strval', $decoded));
            }
        }
        return [];
    }

    /** @return array{options:array<string,string|true>,positional:array<int,string>} */
    public static function parse(): array
    {
        $args = self::args();
        $options = [];
        $positional = [];
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--')) {
                $body = substr($arg, 2);
                if (str_contains($body, '=')) {
                    [$key, $value] = explode('=', $body, 2);
                    $options[$key] = $value;
                } else {
                    $next = $args[$i + 1] ?? null;
                    if ($next !== null && !str_starts_with($next, '--')) {
                        $options[$body] = $next;
                        $i++;
                    } else {
                        $options[$body] = true;
                    }
                }
            } else {
                $positional[] = $arg;
            }
        }
        return ['options' => $options, 'positional' => $positional];
    }

    public static function option(string $name, ?string $default = null): ?string
    {
        $parsed = self::parse();
        $value = $parsed['options'][$name] ?? null;
        if ($value === true) {
            return $default;
        }
        return $value ?? $default;
    }

    public static function flag(string $name): bool
    {
        $parsed = self::parse();
        return array_key_exists($name, $parsed['options']);
    }

    public static function requireRoot(): void
    {
        if (!defined('DOORLOCK_ROOT')) {
            file_put_contents('php://stderr', "This script must be run through includes/bootstrap.php\n");
            exit(1);
        }
    }

    public static function out(string $message = ''): void
    {
        echo $message . PHP_EOL;
    }

    public static function err(string $message): void
    {
        file_put_contents('php://stderr', $message . PHP_EOL);
    }

    /** Prompt for a value without echoing it (falls back to a flag value). */
    public static function secret(?string $provided, string $prompt): ?string
    {
        if ($provided !== null && $provided !== '') {
            return $provided;
        }
        if (!function_exists('shell_exec') || !is_file('/dev/tty')) {
            return null;
        }
        echo $prompt;
        $stty = @shell_exec('stty -g 2>/dev/null');
        @shell_exec('stty -echo 2>/dev/null');
        $stdin = fopen('php://stdin', 'r');
        $value = $stdin !== false ? fgets($stdin) : false;
        if ($stdin !== false) {
            fclose($stdin);
        }
        @shell_exec('stty ' . ($stty !== null ? escapeshellarg(trim($stty)) : 'echo') . ' 2>/dev/null');
        echo PHP_EOL;
        return $value !== false ? trim($value) : null;
    }
}
