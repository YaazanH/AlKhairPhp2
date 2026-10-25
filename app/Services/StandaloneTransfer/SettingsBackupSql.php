<?php

namespace App\Services\StandaloneTransfer;

use App\Support\Backups\MySqlDumpReader;
use Generator;
use RuntimeException;

/** Reads only literal rows. No SQL from a backup is sent to a database. */
class SettingsBackupSql
{
    public function __construct(private TransferContract $contract) {}

    public function rows(string $path, callable $created): Generator
    {
        try {
            yield from $this->read($path, $created);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(in_array($exception->getMessage(), ['unsupported_schema', 'unsupported_sql_dump'], true) ? $exception->getMessage() : 'unsupported_sql_dump');
        }
    }

    private function read(string $path, callable $created): Generator
    {
        $expected = $this->contract->schema()['tables'];
        $tables = [];
        foreach (MySqlDumpReader::statements($path, 64 * 1024 * 1024) as $statement) {
            $reader = new MySqlDumpReader($statement);
            if ($reader->consume('CREATE')) {
                $reader->expect('TABLE');
                $name = $reader->identifier();
                if (! isset($expected[$name]) || isset($tables[$name])) {
                    throw new RuntimeException('unsupported_schema');
                }
                $reader->expect('(');
                $columns = [];
                do {
                    $token = $reader->take();
                    if ($token[0] === 'identifier') {
                        $columns[] = $token[1];
                    } elseif ($token[0] !== 'word' || ! in_array(strtoupper($token[1]), ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'CHECK', 'FULLTEXT', 'SPATIAL'], true)) {
                        throw new RuntimeException('unsupported_sql_dump');
                    }
                    $depth = 0;
                    while (($token = $reader->peek()) !== null) {
                        if ($depth === 0 && $token[0] === 'symbol' && in_array($token[1], [',', ')'], true)) {
                            break;
                        }
                        $token = $reader->take();
                        if ($token[0] === 'symbol' && $token[1] === '(') {
                            $depth++;
                        } elseif ($token[0] === 'symbol' && $token[1] === ')') {
                            $depth--;
                        }
                    }
                } while ($reader->consume(','));
                $reader->expect(')');
                $sorted = $columns;
                sort($sorted, SORT_STRING);
                if ($sorted !== $expected[$name]) {
                    throw new RuntimeException('unsupported_schema');
                }
                $tables[$name] = $columns;
                $created($name);
                // Source DDL and table options are never executed. Target DDL is trusted code.
            } elseif ($reader->consume('INSERT')) {
                $reader->expect('INTO');
                $name = $reader->identifier();
                if (! isset($tables[$name])) {
                    throw new RuntimeException('unsupported_schema');
                }
                $columns = $tables[$name];
                if ($reader->consume('(')) {
                    $columns = [];
                    do {
                        $columns[] = $reader->identifier();
                    } while ($reader->consume(','));
                    $reader->expect(')');
                    $sorted = $columns;
                    sort($sorted, SORT_STRING);
                    if ($sorted !== $expected[$name]) {
                        throw new RuntimeException('unsupported_schema');
                    }
                }
                $reader->expect('VALUES');
                do {
                    $reader->expect('(');
                    $row = [];
                    foreach ($columns as $index => $column) {
                        if ($index > 0) {
                            $reader->expect(',');
                        }
                        [$type, $value] = $reader->take();
                        if ($type === 'word' && strtoupper($value) === 'NULL') {
                            $value = null;
                        } elseif ($type === 'word' && in_array(strtoupper($value), ['_BINARY', '_UTF8', '_UTF8MB4', 'N'], true)) {
                            [$type, $value] = $reader->take();
                            if ($type !== 'string') {
                                throw new RuntimeException('unsupported_sql_dump');
                            }
                        } elseif (! in_array($type, ['number', 'string', 'binary'], true)) {
                            throw new RuntimeException('unsupported_sql_dump');
                        }
                        $row[$column] = $value; // Keep decimal and large integer literals as exact strings.
                    }
                    $reader->expect(')');
                    yield [$name, $row];
                } while ($reader->consume(','));
                $reader->end();
            } elseif ($reader->consume('SET')) {
                if (preg_match('/\b(?:NO_BACKSLASH_ESCAPES|ANSI_QUOTES)\b/i', $statement)) {
                    throw new RuntimeException('unsupported_sql_dump');
                }
                if ($reader->consume('NAMES') && ! in_array(strtolower($reader->identifier()), ['utf8', 'utf8mb3', 'utf8mb4'], true)) {
                    throw new RuntimeException('unsupported_sql_dump');
                }
            } elseif ($reader->consume('DROP')) {
                $reader->expect('TABLE');
                $reader->expect('IF');
                $reader->expect('EXISTS');
                $name = $reader->identifier();
                if (! isset($expected[$name]) || isset($tables[$name])) {
                    throw new RuntimeException('unsupported_schema');
                }
                $reader->end();
            } elseif ($reader->consume('LOCK')) {
                $reader->expect('TABLES');
                do {
                    if (! isset($tables[$reader->identifier()])) {
                        throw new RuntimeException('unsupported_schema');
                    }
                    $reader->expect('WRITE');
                } while ($reader->consume(','));
                $reader->end();
            } elseif ($reader->consume('UNLOCK')) {
                $reader->expect('TABLES');
                $reader->end();
            } elseif ($reader->consume('ALTER')) {
                $reader->expect('TABLE');
                if (! isset($tables[$reader->identifier()])) {
                    throw new RuntimeException('unsupported_schema');
                }
                if (! $reader->consume('DISABLE')) {
                    $reader->expect('ENABLE');
                }
                $reader->expect('KEYS');
                $reader->end();
            } elseif ($reader->consume('START')) {
                $reader->expect('TRANSACTION');
                $reader->end();
            } elseif ($reader->consume('COMMIT')) {
                $reader->end();
            } else {
                throw new RuntimeException('unsupported_sql_dump');
            }
        }
        $names = array_keys($tables);
        sort($names, SORT_STRING);
        if ($names !== array_keys($expected)) {
            throw new RuntimeException('unsupported_schema');
        }
    }
}
