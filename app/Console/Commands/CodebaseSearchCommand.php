<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;

class CodebaseSearchCommand extends Command
{
    protected $signature = 'codebase:search
        {query : English or Arabic words to find in first-party source code}
        {--limit=25 : Maximum number of ranked results}
        {--database=storage/app/private/codebase-search.sqlite : SQLite FTS index path relative to the project root}';

    protected $description = 'Search the generated whole-project source index and return ranked file and route matches.';

    public function handle(): int
    {
        $databasePath = $this->databasePath();

        if (! File::isFile($databasePath)) {
            $this->components->error('The codebase search index does not exist.');
            $this->line('Create it with: php artisan codebase:index');

            return self::FAILURE;
        }

        $tokens = $this->queryTokens((string) $this->argument('query'));

        if ($tokens === []) {
            $this->components->error('Enter at least one letter or number to search for.');

            return self::INVALID;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $ftsQuery = collect($tokens)
            ->map(fn (string $token): string => '"'.str_replace('"', '""', $token).'"*')
            ->implode(' AND ');
        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $statement = $pdo->prepare("SELECT kind, path, category, symbols, content,
                bm25(code_search, 0.0, 8.0, 3.0, 5.0, 1.0) AS rank
            FROM code_search
            WHERE code_search MATCH :query
            ORDER BY rank
            LIMIT {$limit}");
        $statement->execute(['query' => $ftsQuery]);
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($results === []) {
            $this->components->warn('No indexed source matched: '.implode(' ', $tokens));

            return self::SUCCESS;
        }

        $rows = collect($results)->map(function (array $result) use ($tokens): array {
            if ($result['kind'] === 'route') {
                return [
                    $result['path'],
                    'Route',
                    Str::limit(trim($result['symbols']), 120),
                ];
            }

            [$lineNumber, $excerpt] = $this->matchingLine((string) $result['content'], $tokens);

            return [
                $result['path'].':'.$lineNumber,
                $result['category'],
                Str::limit($excerpt, 140),
            ];
        })->all();

        $this->table(['Location', 'Area', 'Matching source'], $rows);
        $this->newLine();
        $this->line(number_format(count($results)).' ranked result(s). Regenerate with `php artisan codebase:index` after structural changes.');

        return self::SUCCESS;
    }

    protected function databasePath(): string
    {
        $path = trim((string) $this->option('database'));

        return Str::startsWith($path, ['/', '\\']) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : base_path($path);
    }

    /** @return list<string> */
    protected function queryTokens(string $query): array
    {
        return collect(preg_split('/[^\p{L}\p{N}_]+/u', Str::lower($query)) ?: [])
            ->filter(fn (string $token): bool => $token !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $tokens
     * @return array{int, string}
     */
    protected function matchingLine(string $content, array $tokens): array
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $fallback = null;

        foreach ($lines as $index => $line) {
            $normalizedLine = Str::lower($line);
            $matches = collect($tokens)->filter(fn (string $token): bool => str_contains($normalizedLine, $token))->count();

            if ($matches === count($tokens)) {
                return [$index + 1, trim($line)];
            }

            if ($matches > 0 && $fallback === null) {
                $fallback = [$index + 1, trim($line)];
            }
        }

        return $fallback ?? [1, 'Matched indexed file metadata or symbol name.'];
    }
}
