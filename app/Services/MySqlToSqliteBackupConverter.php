<?php

namespace App\Services;

use App\Support\Backups\MySqlDumpReader;
use PDO;
use RuntimeException;
use Throwable;

class MySqlToSqliteBackupConverter
{
    /** Build and validate a separate database. Never connect to the application's database. */
    public function convert(string $sourcePath, string $destinationPath, array $expectedTables = []): array
    {
        $file = fopen($destinationPath, 'xb');
        if ($file === false) {
            throw new RuntimeException('The converted database cannot be created.');
        }
        fclose($file);
        chmod($destinationPath, 0600);
        $pdo = null;
        $tables = [];

        try {
            $pdo = new PDO('sqlite:'.$destinationPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->beginTransaction();
            foreach (MySqlDumpReader::statements($sourcePath) as $statement) {
                $reader = new MySqlDumpReader($statement);
                if ($reader->consume('CREATE')) {
                    $this->createTable($reader, $pdo, $tables);
                } elseif ($reader->consume('INSERT')) {
                    $this->insertRows($reader, $pdo, $tables);
                } else {
                    $this->readHousekeeping($statement, $reader, $tables);
                }
            }

            if ($tables === []) {
                throw new RuntimeException('The MySQL dump contains no tables.');
            }
            $actualTables = array_keys($tables);
            sort($actualTables);
            sort($expectedTables);
            if ($expectedTables !== [] && $actualTables !== $expectedTables) {
                throw new RuntimeException('The converted table inventory does not match the backup manifest.');
            }
            foreach ($tables as $name => $table) {
                if ((int) $pdo->query('SELECT COUNT(*) FROM '.$this->quote($name))->fetchColumn() !== $table['rows']) {
                    throw new RuntimeException('The converted database row count does not match the dump.');
                }
                if ($table['auto'] !== null && $table['next'] > 1) {
                    $sequence = $pdo->prepare('SELECT seq FROM sqlite_sequence WHERE name = ?');
                    $sequence->execute([$name]);
                    $current = $sequence->fetchColumn();
                    if ($current === false) {
                        $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)')->execute([$name, $table['next'] - 1]);
                    } else {
                        $pdo->prepare('UPDATE sqlite_sequence SET seq = ? WHERE name = ?')->execute([max((int) $current, $table['next'] - 1), $name]);
                    }
                }
            }
            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('The converted database contains invalid foreign-key relationships.');
            }
            $pdo->commit();
            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('The converted SQLite database failed its integrity check.');
            }
            $pdo = null;

            return ['tables' => count($tables), 'rows' => array_sum(array_column($tables, 'rows'))];
        } catch (Throwable $exception) {
            if ($pdo?->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo = null;
            @unlink($destinationPath);
            @unlink($destinationPath.'-journal');

            throw $exception;
        }
    }

    private function createTable(MySqlDumpReader $reader, PDO $pdo, array &$tables): void
    {
        $reader->expect('TABLE');
        if ($reader->consume('IF')) {
            $reader->expect('NOT');
            $reader->expect('EXISTS');
        }
        $name = $reader->identifier();
        if (isset($tables[$name]) || str_starts_with(strtolower($name), 'sqlite_')) {
            throw new RuntimeException('The dump contains a duplicate or reserved table.');
        }
        $reader->expect('(');
        $columns = [];
        $constraints = [];
        $indexes = [];
        $primary = [];
        $auto = null;
        do {
            if ($reader->consume('PRIMARY')) {
                $reader->expect('KEY');
                $primary = $this->columnList($reader);
            } elseif ($reader->consume('UNIQUE')) {
                $reader->expect('KEY');
                $index = $reader->identifier();
                $indexes[] = [$index, $this->columnList($reader), true];
            } elseif ($reader->consume('KEY') || $reader->consume('INDEX')) {
                $index = $reader->identifier();
                $indexes[] = [$index, $this->columnList($reader), false];
            } elseif ($reader->consume('CONSTRAINT')) {
                $constraint = $reader->identifier();
                $reader->expect('FOREIGN');
                $reader->expect('KEY');
                $localColumns = $this->columnList($reader);
                $reader->expect('REFERENCES');
                $foreignTable = $reader->identifier();
                $foreignColumns = $this->columnList($reader);
                $sql = 'CONSTRAINT '.$this->quote($constraint).' FOREIGN KEY ('.$this->quotedList($localColumns).') REFERENCES '.$this->quote($foreignTable).' ('.$this->quotedList($foreignColumns).')';
                while ($reader->consume('ON')) {
                    if ($reader->consume('DELETE')) {
                        $event = 'DELETE';
                    } else {
                        $reader->expect('UPDATE');
                        $event = 'UPDATE';
                    }
                    $action = strtoupper($reader->identifier());
                    if ($action === 'SET') {
                        $reader->expect('NULL');
                        $action = 'SET NULL';
                    } elseif ($action === 'NO') {
                        $reader->expect('ACTION');
                        $action = 'NO ACTION';
                    } elseif (! in_array($action, ['CASCADE', 'RESTRICT'], true)) {
                        throw new RuntimeException('Unsupported MySQL foreign-key action.');
                    }
                    $sql .= ' ON '.$event.' '.$action;
                }
                $constraints[] = $sql;
            } else {
                $column = $reader->identifier();
                if (isset($columns[$column])) {
                    throw new RuntimeException('The dump contains duplicate columns.');
                }
                $type = strtolower($reader->identifier());
                $parameters = [];
                if ($reader->consume('(')) {
                    do {
                        $parameters[] = $reader->take();
                    } while ($reader->consume(','));
                    $reader->expect(')');
                }
                $sqliteType = match ($type) {
                    'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'bool', 'boolean' => 'INTEGER',
                    'decimal', 'numeric' => 'NUMERIC',
                    'float', 'double', 'real' => 'REAL',
                    'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' => 'BLOB',
                    'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json', 'date', 'datetime', 'timestamp', 'time', 'year', 'enum' => 'TEXT',
                    default => throw new RuntimeException('The dump uses an unsupported MySQL column type.'),
                };
                $definition = $this->quote($column).' '.$sqliteType;
                while (($token = $reader->peek()) && ! in_array($token[1], [',', ')'], true)) {
                    if ($reader->consume('UNSIGNED')) {
                        $definition .= ' CHECK ('.$this->quote($column).' >= 0)';
                    } elseif ($reader->consume('NOT')) {
                        $reader->expect('NULL');
                        $definition .= ' NOT NULL';
                    } elseif ($reader->consume('NULL')) {
                        // SQLite columns are nullable unless NOT NULL is declared.
                    } elseif ($reader->consume('AUTO_INCREMENT')) {
                        if ($auto !== null || $sqliteType !== 'INTEGER') {
                            throw new RuntimeException('Unsupported MySQL auto-increment definition.');
                        }
                        $auto = $column;
                    } elseif ($reader->consume('DEFAULT')) {
                        if ($reader->consume('CURRENT_TIMESTAMP')) {
                            // Fractional/expression defaults are deliberately rejected by the next token check.
                            $definition .= ' DEFAULT CURRENT_TIMESTAMP';
                        } else {
                            [$value, $binding] = $this->literal($reader);
                            if ($binding === PDO::PARAM_LOB || (is_string($value) && str_contains($value, "\0"))) {
                                throw new RuntimeException('Unsupported MySQL column default.');
                            }
                            $definition .= ' DEFAULT '.($value === null ? 'NULL' : $pdo->quote((string) $value));
                        }
                    } elseif ($reader->consume('COLLATE')) {
                        $reader->identifier(); // Use SQLite's native collation, as Laravel's SQLite migrations do.
                    } elseif ($reader->consume('CHARACTER')) {
                        $reader->expect('SET');
                        $this->assertCharset($reader->identifier());
                    } elseif ($reader->consume('COMMENT')) {
                        if ($reader->take()[0] !== 'string') {
                            throw new RuntimeException('Invalid MySQL column comment.');
                        }
                    } else {
                        throw new RuntimeException('The dump uses an unsupported MySQL column attribute.');
                    }
                }
                if ($type === 'enum') {
                    if ($parameters === [] || array_filter($parameters, fn ($token) => $token[0] !== 'string')) {
                        throw new RuntimeException('Invalid MySQL enum definition.');
                    }
                    $definition .= ' CHECK ('.$this->quote($column).' IN ('.implode(', ', array_map(fn ($token) => $pdo->quote($token[1]), $parameters)).'))';
                }
                $columns[$column] = ['sql' => $definition, 'type' => $sqliteType];
            }
        } while ($reader->consume(','));
        $reader->expect(')');
        $next = 1;
        while ($reader->peek() !== null) {
            $reader->consume('DEFAULT');
            $option = strtoupper($reader->identifier());
            if ($option === 'CHARACTER') {
                $reader->expect('SET');
                $option = 'CHARSET';
            }
            $reader->consume('=');
            [$kind, $value] = $reader->take();
            if ($option === 'AUTO_INCREMENT') {
                $next = $this->integer($value);
            } elseif ($option === 'CHARSET') {
                $this->assertCharset($value);
            } elseif (! in_array($option, ['ENGINE', 'COLLATE', 'COMMENT', 'ROW_FORMAT', 'KEY_BLOCK_SIZE', 'STATS_PERSISTENT'], true)) {
                throw new RuntimeException('The dump uses unsupported MySQL table options.');
            }
        }
        if ($columns === []) {
            throw new RuntimeException('The dump contains a table without columns.');
        }
        foreach ([$primary, ...array_column($indexes, 1)] as $keyColumns) {
            if (array_diff($keyColumns, array_keys($columns))) {
                throw new RuntimeException('The dump defines an index on an unknown column.');
            }
        }
        if ($auto !== null) {
            if ($primary !== [$auto]) {
                throw new RuntimeException('MySQL auto-increment requires a single primary key for SQLite conversion.');
            }
            $columns[$auto]['sql'] .= ' PRIMARY KEY AUTOINCREMENT';
        } elseif ($primary !== []) {
            $constraints[] = 'PRIMARY KEY ('.$this->quotedList($primary).')';
        }
        $pdo->exec('CREATE TABLE '.$this->quote($name).' ('.implode(', ', [...array_column($columns, 'sql'), ...$constraints]).')');
        foreach ($indexes as [$index, $indexColumns, $unique]) {
            // MySQL index names are table-local; SQLite index names are database-wide.
            $existing = $pdo->prepare('SELECT 1 FROM sqlite_master WHERE name = ?');
            $existing->execute([$index]);
            $indexName = $existing->fetchColumn() || str_starts_with(strtolower($index), 'sqlite_')
                ? $name.'__'.$index : $index;
            $pdo->exec('CREATE '.($unique ? 'UNIQUE ' : '').'INDEX '.$this->quote($indexName).' ON '.$this->quote($name).' ('.$this->quotedList($indexColumns).')');
        }
        $tables[$name] = ['columns' => $columns, 'rows' => 0, 'auto' => $auto, 'next' => $next];
    }

    private function insertRows(MySqlDumpReader $reader, PDO $pdo, array &$tables): void
    {
        $reader->expect('INTO');
        $table = $reader->identifier();
        if (! isset($tables[$table])) {
            throw new RuntimeException('The dump inserts into an unknown table.');
        }
        $columns = ($reader->peek()[1] ?? '') === '('
            ? $this->columnList($reader)
            : array_keys($tables[$table]['columns']);
        if (count(array_unique($columns)) !== count($columns) || array_diff($columns, array_keys($tables[$table]['columns']))) {
            throw new RuntimeException('The dump inserts into unknown or duplicate columns.');
        }
        $reader->expect('VALUES');
        $query = $pdo->prepare('INSERT INTO '.$this->quote($table).' ('.$this->quotedList($columns).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
        do {
            $reader->expect('(');
            foreach ($columns as $index => $column) {
                if ($index !== 0) {
                    $reader->expect(',');
                }
                [$value, $binding] = $this->literal($reader);
                $columnType = $tables[$table]['columns'][$column]['type'];
                if ($value !== null && $columnType === 'BLOB') {
                    $binding = PDO::PARAM_LOB;
                } elseif ($binding === PDO::PARAM_LOB && $columnType === 'TEXT') {
                    $binding = PDO::PARAM_STR;
                } elseif ($binding === PDO::PARAM_LOB) {
                    throw new RuntimeException('Binary literals in numeric columns cannot be converted safely.');
                }
                $query->bindValue($index + 1, $value, $binding);
            }
            $reader->expect(')');
            $query->execute();
            $tables[$table]['rows']++;
        } while ($reader->consume(','));
        $reader->end();
    }

    private function literal(MySqlDumpReader $reader): array
    {
        [$kind, $value] = $reader->take();
        if ($kind === 'word') {
            $word = strtoupper($value);
            if ($word === 'NULL') {
                return [null, PDO::PARAM_NULL];
            }
            if (in_array($word, ['TRUE', 'FALSE'], true)) {
                return [(int) ($word === 'TRUE'), PDO::PARAM_INT];
            }
            if (in_array($word, ['_BINARY', '_UTF8', '_UTF8MB4', 'N'], true)) {
                [$kind, $value] = $reader->take();
                if ($kind === 'string') {
                    return [$value, $word === '_BINARY' ? PDO::PARAM_LOB : PDO::PARAM_STR];
                }
            }
            throw new RuntimeException('The dump contains an unsupported SQL value or expression.');
        }
        if ($kind === 'number') {
            if (preg_match('/^[+-]?\d+$/D', $value)) {
                return [$this->integer($value), PDO::PARAM_INT];
            }

            return [$value, PDO::PARAM_STR];
        }
        if (in_array($kind, ['string', 'binary'], true)) {
            return [$value, $kind === 'binary' ? PDO::PARAM_LOB : PDO::PARAM_STR];
        }

        throw new RuntimeException('The dump contains an unsupported SQL value.');
    }

    private function integer(string $value): int
    {
        if (! preg_match('/^[+-]?\d+$/D', $value)) {
            throw new RuntimeException('Invalid integer in MySQL dump.');
        }
        $digits = ltrim(ltrim($value, '+-'), '0');
        $limit = str_starts_with($value, '-') ? '9223372036854775808' : '9223372036854775807';
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new RuntimeException('A MySQL integer exceeds the SQLite integer range.');
        }

        return (int) $value;
    }

    private function columnList(MySqlDumpReader $reader): array
    {
        $reader->expect('(');
        $columns = [];
        do {
            $columns[] = $reader->identifier();
        } while ($reader->consume(','));
        $reader->expect(')');

        return $columns;
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quotedList(array $columns): string
    {
        return implode(', ', array_map($this->quote(...), $columns));
    }

    private function assertCharset(string $charset): void
    {
        if (! in_array(strtolower($charset), ['utf8', 'utf8mb3', 'utf8mb4', 'binary'], true)) {
            throw new RuntimeException('Only UTF-8 MySQL dumps can be converted to SQLite.');
        }
    }

    private function readHousekeeping(string $sql, MySqlDumpReader $reader, array $tables): void
    {
        if ($reader->consume('SET')) {
            if (preg_match('/\b(?:NO_BACKSLASH_ESCAPES|ANSI_QUOTES)\b/i', $sql)) {
                throw new RuntimeException('The dump uses an unsupported MySQL SQL mode.');
            }
            if ($reader->consume('NAMES')) {
                $this->assertCharset($reader->identifier());
            }

            return; // Session variables affect MySQL only; never execute them on SQLite.
        }
        if ($reader->consume('DROP')) {
            $reader->expect('TABLE');
            $reader->expect('IF');
            $reader->expect('EXISTS');
            $name = $reader->identifier();
            if (isset($tables[$name])) {
                throw new RuntimeException('The dump drops a table after creating it.');
            }
            $reader->end();

            return; // Destination is a newly created, empty file.
        }
        if ($reader->consume('LOCK')) {
            $reader->expect('TABLES');
            do {
                $reader->identifier();
                $reader->expect('WRITE');
            } while ($reader->consume(','));
            $reader->end();

            return;
        }
        if ($reader->consume('UNLOCK')) {
            $reader->expect('TABLES');
            $reader->end();

            return;
        }
        if ($reader->consume('ALTER')) {
            $reader->expect('TABLE');
            $reader->identifier();
            if (! $reader->consume('DISABLE')) {
                $reader->expect('ENABLE');
            }
            $reader->expect('KEYS');
            $reader->end();

            return;
        }
        if ($reader->consume('START')) {
            $reader->expect('TRANSACTION');
        } elseif (! $reader->consume('COMMIT')) {
            throw new RuntimeException('The dump contains SQL that cannot be converted safely to SQLite.');
        }
        $reader->end();
    }
}
