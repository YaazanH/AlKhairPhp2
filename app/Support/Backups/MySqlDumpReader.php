<?php

namespace App\Support\Backups;

use Generator;
use RuntimeException;

/** Reads dump syntax without sending any source SQL to a database connection. */
class MySqlDumpReader
{
    private const ROCKSDB_SETUP = [
        "SELECT COUNT(*) INTO @rocksdb_has_p_s_session_variables FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'performance_schema' AND TABLE_NAME = 'session_variables'",
        "SET @rocksdb_get_is_supported = IF (@rocksdb_has_p_s_session_variables, 'SELECT COUNT(*) INTO @rocksdb_is_supported FROM performance_schema.session_variables WHERE VARIABLE_NAME=\\'rocksdb_bulk_load\\'', 'SELECT 0')",
        'PREPARE s FROM @rocksdb_get_is_supported',
        'EXECUTE s',
        'DEALLOCATE PREPARE s',
        "SET @rocksdb_enable_bulk_load = IF (@rocksdb_is_supported, 'SET SESSION rocksdb_bulk_load = 1', 'SET @rocksdb_dummy_bulk_load = 0')",
        'PREPARE s FROM @rocksdb_enable_bulk_load',
        'EXECUTE s',
        'DEALLOCATE PREPARE s',
    ];

    private const ROCKSDB_RESET = [
        "SET @disable_bulk_load = IF (@is_rocksdb_supported, 'SET SESSION rocksdb_bulk_load = @old_rocksdb_bulk_load', 'SET @dummy_rocksdb_bulk_load = 0')",
        'PREPARE s FROM @disable_bulk_load',
        'EXECUTE s',
        'DEALLOCATE PREPARE s',
    ];

    private Generator $tokens;

    public function __construct(string $statement)
    {
        $this->tokens = self::tokenize($statement);
    }

    public function peek(): ?array
    {
        return $this->tokens->valid() ? $this->tokens->current() : null;
    }

    public function take(): array
    {
        $token = $this->peek() ?? throw new RuntimeException('The MySQL dump statement is incomplete.');
        $this->tokens->next();

        return $token;
    }

    public function consume(string $value): bool
    {
        $token = $this->peek();
        if ($token && in_array($token[0], ['word', 'symbol'], true) && strcasecmp($token[1], $value) === 0) {
            $this->take();

            return true;
        }

        return false;
    }

    public function expect(string $value): void
    {
        if (! $this->consume($value)) {
            throw new RuntimeException('Unsupported or incomplete MySQL dump syntax.');
        }
    }

    public function identifier(): string
    {
        [$type, $value] = $this->take();
        if (! in_array($type, ['identifier', 'word'], true) || $value === '' || str_contains($value, "\0")) {
            throw new RuntimeException('The MySQL dump contains an invalid identifier.');
        }

        return $value;
    }

    public function end(): void
    {
        if ($this->peek() !== null) {
            throw new RuntimeException('The MySQL dump contains an unsupported SQL clause.');
        }
    }

    public static function statements(string $path, int $maximumBytes = 0): Generator
    {
        $block = null;
        $step = 0;
        $setupSeen = false;
        $resetSeen = false;
        $tablesSeen = false;
        foreach (self::rawStatements($path, $maximumBytes) as $statement) {
            if ($block !== null) {
                if (! self::sameSyntax($statement, $block[$step])) {
                    throw new RuntimeException('Unsupported RocksDB dump initialization sequence.');
                }
                if (++$step === count($block)) {
                    $block = null;
                }

                continue;
            }
            if (! $tablesSeen && ! $setupSeen && preg_match('/^SELECT\b/i', $statement)
                && self::sameSyntax($statement, self::ROCKSDB_SETUP[0])) {
                $setupSeen = true;
                $block = self::ROCKSDB_SETUP;
                $step = 1;

                continue;
            }
            if ($setupSeen && ! $resetSeen && preg_match('/^SET\s+@disable_bulk_load\b/i', $statement)
                && self::sameSyntax($statement, self::ROCKSDB_RESET[0])) {
                $resetSeen = true;
                $block = self::ROCKSDB_RESET;
                $step = 1;

                continue;
            }
            $tablesSeen = $tablesSeen || (bool) preg_match('/^(?:CREATE|DROP|INSERT|ALTER|LOCK|UNLOCK)\b/i', $statement);
            yield $statement;
        }
        if ($block !== null || $setupSeen !== $resetSeen) {
            throw new RuntimeException('The RocksDB dump initialization sequence is incomplete.');
        }
    }

    /** Only the exact paired dump session blocks are ignored; their SQL is never executed. */
    private static function sameSyntax(string $actual, string $expected): bool
    {
        $actual = new self($actual);
        $expected = new self($expected);
        while ($expected->peek() !== null) {
            if ($actual->peek() === null) {
                return false;
            }
            [$actualType, $actualValue] = $actual->take();
            [$expectedType, $expectedValue] = $expected->take();
            if ($actualType !== $expectedType || ($actualType === 'word'
                ? strcasecmp($actualValue, $expectedValue) !== 0 : $actualValue !== $expectedValue)) {
                return false;
            }
        }

        return $actual->peek() === null;
    }

    private static function rawStatements(string $path, int $maximumBytes): Generator
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The MySQL dump cannot be opened.');
        }
        $statement = '';
        $quote = null;
        $escaped = false;
        $comment = null;

        try {
            while (($line = self::line($stream, $maximumBytes)) !== false) {
                if ($maximumBytes > 0 && strlen($statement) + strlen($comment ?? '') + strlen($line) > $maximumBytes) {
                    throw new RuntimeException('The MySQL dump statement exceeds the processing limit.');
                }
                $length = strlen($line);
                for ($i = 0; $i < $length; $i++) {
                    $char = $line[$i];
                    $next = $line[$i + 1] ?? '';
                    if ($comment !== null) {
                        if ($char === '*' && $next === '/') {
                            // Executable comments can contain real DDL, so do not silently discard them.
                            if (preg_match('/^(?:!|M!)(?:\d+)?\s*(.*)$/s', $comment, $match)) {
                                $body = trim($match[1]);
                                if ($body !== '\\- enable the sandbox mode') {
                                    if (trim($statement) !== '') {
                                        throw new RuntimeException('Inline MySQL executable comments are not supported.');
                                    }
                                    yield rtrim($body, ';');
                                }
                            }
                            $comment = null;
                            $statement .= ' ';
                            $i++;
                        } else {
                            $comment .= $char;
                        }

                        continue;
                    }
                    if ($quote !== null) {
                        $statement .= $char;
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($char === '\\') {
                            $escaped = true;
                        } elseif ($char === $quote) {
                            if ($next === $quote) {
                                $statement .= $next;
                                $i++;
                            } else {
                                $quote = null;
                            }
                        }

                        continue;
                    }
                    if ($char === '#' || ($char === '-' && $next === '-' && ctype_space($line[$i + 2] ?? ' '))) {
                        $statement .= "\n";
                        break;
                    }
                    if ($char === '/' && $next === '*') {
                        $comment = '';
                        $i++;
                    } elseif (in_array($char, ["'", '"', '`'], true)) {
                        $quote = $char;
                        $statement .= $char;
                    } elseif ($char === ';') {
                        if (trim($statement) !== '') {
                            yield trim($statement);
                        }
                        $statement = '';
                    } else {
                        $statement .= $char;
                    }
                }
            }
            if (! feof($stream) || $quote !== null || $comment !== null || trim($statement) !== '') {
                throw new RuntimeException('The MySQL dump is truncated or incomplete.');
            }
        } finally {
            fclose($stream);
        }
    }

    private static function line($stream, int $maximumBytes): string|false
    {
        if ($maximumBytes === 0) {
            return fgets($stream);
        }
        $line = '';
        while (($part = fgets($stream, 64 * 1024 + 1)) !== false) {
            $line .= $part;
            if (strlen($line) > $maximumBytes) {
                throw new RuntimeException('The MySQL dump statement exceeds the processing limit.');
            }
            if (str_ends_with($line, "\n")) {
                break;
            }
        }

        return $line === '' ? false : $line;
    }

    private static function tokenize(string $sql): Generator
    {
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            if (ctype_space($char)) {
                $i++;

                continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $value = '';
                $closed = false;
                for ($i++; $i < $length; $i++) {
                    $char = $sql[$i];
                    if ($char === '\\' && $quote !== '`') {
                        $escaped = $sql[++$i] ?? throw new RuntimeException('Truncated MySQL string.');
                        $value .= match ($escaped) {
                            '0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a",
                            '%', '_' => '\\'.$escaped,
                            default => $escaped,
                        };
                    } elseif ($char === $quote) {
                        if (($sql[$i + 1] ?? '') === $quote) {
                            $value .= $quote;
                            $i++;
                        } else {
                            $i++;
                            $closed = true;
                            break;
                        }
                    } else {
                        $value .= $char;
                    }
                }
                if (! $closed) {
                    throw new RuntimeException('Truncated MySQL quoted value.');
                }
                yield [$quote === '`' ? 'identifier' : 'string', $value];
            } elseif (preg_match('/\G0x([0-9a-f]*)/Ai', $sql, $match, 0, $i)) {
                if (strlen($match[1]) % 2 !== 0) {
                    throw new RuntimeException('Invalid MySQL binary literal.');
                }
                $i += strlen($match[0]);
                yield ['binary', hex2bin($match[1])];
            } elseif (preg_match('/\G[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?/Ai', $sql, $match, 0, $i)) {
                $i += strlen($match[0]);
                yield ['number', $match[0]];
            } elseif (preg_match('/\G[A-Z_$][A-Z0-9_$]*/Ai', $sql, $match, 0, $i)) {
                $i += strlen($match[0]);
                yield ['word', $match[0]];
            } else {
                $i++;
                yield ['symbol', $char];
            }
        }
    }
}
