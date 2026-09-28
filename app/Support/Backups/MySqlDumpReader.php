<?php

namespace App\Support\Backups;

use Generator;
use RuntimeException;

/** Reads dump syntax without sending any source SQL to a database connection. */
class MySqlDumpReader
{
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

    public static function statements(string $path): Generator
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
            while (($line = fgets($stream)) !== false) {
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
