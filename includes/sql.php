<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * Minimal SQL script splitter used by the migration runner and installers.
 *
 * Handles single/double quoted strings, backtick identifiers, line comments
 * (-- and #) and block comments. It intentionally does not attempt to parse
 * stored procedure bodies; the project's schema does not use them.
 */
final class SqlScript
{
    /** @return array<int,string> */
    public static function split(string $script): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($script);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $script[$i];
            $next = $i + 1 < $length ? $script[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $buffer .= "\n";
                }
                continue;
            }
            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }
            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($script[$i + 2]) || $script[$i + 2] === '-')) {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
            }

            if ($inSingle) {
                $buffer .= $char;
                if ($char === '\\') {
                    if ($next !== '') {
                        $buffer .= $next;
                        $i++;
                    }
                    continue;
                }
                if ($char === "'") {
                    if ($next === "'") {
                        $buffer .= $next;
                        $i++;
                        continue;
                    }
                    $inSingle = false;
                }
                continue;
            }
            if ($inDouble) {
                $buffer .= $char;
                if ($char === '\\') {
                    if ($next !== '') {
                        $buffer .= $next;
                        $i++;
                    }
                    continue;
                }
                if ($char === '"') {
                    if ($next === '"') {
                        $buffer .= $next;
                        $i++;
                        continue;
                    }
                    $inDouble = false;
                }
                continue;
            }
            if ($inBacktick) {
                $buffer .= $char;
                if ($char === '`') {
                    $inBacktick = false;
                }
                continue;
            }

            if ($char === "'") {
                $inSingle = true;
                $buffer .= $char;
                continue;
            }
            if ($char === '"') {
                $inDouble = true;
                $buffer .= $char;
                continue;
            }
            if ($char === '`') {
                $inBacktick = true;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }
}
