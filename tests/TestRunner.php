<?php

declare(strict_types=1);

namespace DoorLock\Tests;

final class SkippedTest extends \RuntimeException
{
}

final class AssertionFailed extends \RuntimeException
{
}

final class TestRunner
{
    /** @var array<int,array{id:string,description:string,tags:array<int,string>,fn:callable}> */
    private array $tests = [];

    /** @param array<int,string> $tags */
    public function add(string $id, string $description, callable $fn, array $tags = []): void
    {
        if (isset($this->tests[$id])) {
            throw new \RuntimeException("Duplicate test id {$id}");
        }
        $this->tests[$id] = ['id' => $id, 'description' => $description, 'tags' => $tags, 'fn' => $fn];
    }

    /**
     * @return array<int,array{id:string,description:string,status:string,message:string,duration_ms:int,tags:array<int,string>}>
     */
    public function run(?string $filter = null, ?string $tag = null): array
    {
        $results = [];
        foreach ($this->tests as $test) {
            if ($filter !== null && stripos($test['id'], $filter) === false && stripos($test['description'], $filter) === false) {
                continue;
            }
            if ($tag !== null && !in_array($tag, $test['tags'], true)) {
                continue;
            }
            $start = microtime(true);
            $status = 'PASS';
            $message = '';
            Assert::reset();
            try {
                ($test['fn'])();
            } catch (SkippedTest $e) {
                $status = 'BLOCKED';
                $message = $e->getMessage();
            } catch (AssertionFailed $e) {
                $status = 'FAIL';
                $message = $e->getMessage();
            } catch (\Throwable $e) {
                $status = 'FAIL';
                $message = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
            }
            $duration = (int) round((microtime(true) - $start) * 1000);
            $results[] = [
                'id' => $test['id'],
                'description' => $test['description'],
                'status' => $status,
                'message' => $message,
                'duration_ms' => $duration,
                'tags' => $test['tags'],
            ];
            $symbol = match ($status) {
                'PASS' => 'PASS',
                'BLOCKED' => 'BLOCK',
                default => 'FAIL',
            };
            printf("%-5s %-10s %s%s\n", $symbol, $test['id'], $test['description'], $message !== '' ? ' — ' . $message : '');
        }
        return $results;
    }

    /** @return array<int,array{id:string,description:string,tags:array<int,string>}> */
    public function list(): array
    {
        return array_values(array_map(
            static fn (array $t) => ['id' => $t['id'], 'description' => $t['description'], 'tags' => $t['tags']],
            $this->tests
        ));
    }
}

final class Assert
{
    public static function reset(): void
    {
    }

    public static function true(bool $condition, string $message = 'Expected true'): void
    {
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    public static function false(bool $condition, string $message = 'Expected false'): void
    {
        self::true(!$condition, $message);
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ': ' : '') .
                'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
            );
        }
    }

    public static function notSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected === $actual) {
            throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'value must differ');
        }
    }

    public static function null(mixed $actual, string $message = 'Expected null'): void
    {
        self::same(null, $actual, $message);
    }

    public static function contains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . "string does not contain '{$needle}'");
        }
    }

    public static function count(int $expected, array $actual, string $message = ''): void
    {
        self::same($expected, count($actual), $message !== '' ? $message : 'unexpected count');
    }

    public static function throws(callable $fn, string $class, ?string $messageContains = null): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionFailed('Expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
            }
            if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
                throw new AssertionFailed("Exception message does not contain '{$messageContains}': " . $e->getMessage());
            }
            return $e;
        }
        throw new AssertionFailed('Expected exception ' . $class . ' was not thrown');
    }

    public static function skip(string $reason): never
    {
        throw new SkippedTest($reason);
    }
}

function test(string $id, string $description, callable $fn, array $tags = []): void
{
    $GLOBALS['__doorlock_test_runner']->add($id, $description, $fn, $tags);
}
