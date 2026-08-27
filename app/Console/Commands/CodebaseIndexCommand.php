<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use PDO;
use SplFileInfo;

class CodebaseIndexCommand extends Command
{
    protected $signature = 'codebase:index
        {--output=docs/codebase-index.md : Human-readable index path relative to the project root}
        {--database=storage/app/private/codebase-search.sqlite : SQLite FTS index path relative to the project root}';

    protected $description = 'Index first-party source files, symbols, and routes for fast project-wide searching.';

    /** @var list<string> */
    protected const SOURCE_DIRECTORIES = [
        'app',
        'bootstrap',
        'config',
        'database',
        'docker',
        'lang',
        'resources',
        'routes',
        'scripts',
        'tests',
    ];

    /** @var list<string> */
    protected const ROOT_FILES = [
        '.env.example',
        '.gitignore',
        'artisan',
        'composer.json',
        'docker-compose.yml',
        'Dockerfile',
        'package.json',
        'phpunit.xml',
        'vite.config.js',
    ];

    /** @var list<string> */
    protected const SOURCE_EXTENSIONS = [
        'css',
        'html',
        'js',
        'json',
        'md',
        'php',
        'ps1',
        'sh',
        'sql',
        'xml',
        'yml',
        'yaml',
    ];

    public function handle(): int
    {
        $records = $this->sourceRecords();
        $routes = $this->routeRecords();
        $outputPath = $this->absoluteOptionPath('output');
        $databasePath = $this->absoluteOptionPath('database');

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, $this->renderMarkdown($records, $routes, $outputPath));
        $this->writeSearchDatabase($databasePath, $records, $routes);

        $symbolCount = $records->sum(fn (array $record): int => count($record['symbols']));

        $this->components->info('Codebase index generated.');
        $this->table(
            ['Source files', 'Symbols', 'Routes', 'Map', 'Search database'],
            [[
                number_format($records->count()),
                number_format($symbolCount),
                number_format(count($routes)),
                $this->relativeToBase($outputPath),
                $this->relativeToBase($databasePath),
            ]],
        );
        $this->line('Search it with: php artisan codebase:search "assessment score"');

        return self::SUCCESS;
    }

    protected function absoluteOptionPath(string $option): string
    {
        $path = trim((string) $this->option($option));

        if ($path === '') {
            throw new \InvalidArgumentException("The --{$option} path cannot be empty.");
        }

        return Str::startsWith($path, ['/', '\\']) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : base_path($path);
    }

    /**
     * @return Collection<int, array{
     *     path:string,
     *     category:string,
     *     lines:int,
     *     symbols:list<array{type:string,name:string,line:int}>,
     *     content:string
     * }>
     */
    protected function sourceRecords()
    {
        $paths = collect(self::SOURCE_DIRECTORIES)
            ->filter(fn (string $directory): bool => File::isDirectory(base_path($directory)))
            ->flatMap(fn (string $directory) => File::allFiles(base_path($directory)))
            ->filter(fn (SplFileInfo $file): bool => $this->isIndexable($file->getPathname()))
            ->map(fn (SplFileInfo $file): string => $file->getPathname())
            ->merge(collect(self::ROOT_FILES)
                ->map(fn (string $path): string => base_path($path))
                ->filter(fn (string $path): bool => File::isFile($path) && $this->isIndexable($path)))
            ->unique()
            ->sort()
            ->values();

        return $paths->map(function (string $absolutePath): array {
            $content = File::get($absolutePath);
            $path = $this->relativeToBase($absolutePath);

            return [
                'path' => $path,
                'category' => $this->categoryFor($path),
                'lines' => $content === '' ? 0 : substr_count($content, "\n") + 1,
                'symbols' => $this->extractSymbols($path, $content),
                'content' => $content,
            ];
        });
    }

    protected function isIndexable(string $path): bool
    {
        if (! File::isFile($path) || File::size($path) > 2_000_000) {
            return false;
        }

        $filename = basename($path);

        if (in_array($filename, ['artisan', 'Dockerfile'], true) || Str::startsWith($filename, '.env.')) {
            return true;
        }

        return in_array(Str::lower(pathinfo($filename, PATHINFO_EXTENSION)), self::SOURCE_EXTENSIONS, true);
    }

    protected function relativeToBase(string $path): string
    {
        $base = str_replace('\\', '/', rtrim(base_path(), DIRECTORY_SEPARATOR)).'/';
        $normalized = str_replace('\\', '/', $path);

        return Str::startsWith($normalized, $base) ? Str::after($normalized, $base) : $normalized;
    }

    protected function categoryFor(string $path): string
    {
        $categories = [
            'app/Console/Commands/' => 'Application / Console commands',
            'app/Http/Controllers/' => 'Application / HTTP controllers',
            'app/Http/Middleware/' => 'Application / HTTP middleware',
            'app/Livewire/' => 'Application / Livewire support',
            'app/Models/' => 'Application / Models',
            'app/Services/' => 'Application / Services',
            'app/Support/' => 'Application / Support',
            'app/' => 'Application / Other',
            'resources/views/livewire/' => 'UI / Livewire screens',
            'resources/views/components/' => 'UI / Blade components',
            'resources/views/' => 'UI / Other views',
            'resources/js/' => 'Frontend / JavaScript',
            'resources/css/' => 'Frontend / CSS',
            'resources/' => 'Frontend / Other resources',
            'routes/' => 'Routing',
            'database/migrations/' => 'Database / Migrations',
            'database/seeders/' => 'Database / Seeders',
            'database/factories/' => 'Database / Factories',
            'database/' => 'Database / Other',
            'tests/Feature/' => 'Tests / Feature',
            'tests/Unit/' => 'Tests / Unit',
            'tests/' => 'Tests / Support',
            'lang/' => 'Localization',
            'config/' => 'Configuration',
            'bootstrap/' => 'Bootstrap',
            'docker/' => 'Deployment / Docker',
            'scripts/' => 'Developer scripts',
        ];

        foreach ($categories as $prefix => $category) {
            if (Str::startsWith($path, $prefix)) {
                return $category;
            }
        }

        return 'Project root';
    }

    /**
     * @return list<array{type:string,name:string,line:int}>
     */
    protected function extractSymbols(string $path, string $content): array
    {
        $symbols = [];
        $seen = [];
        $isPhp = Str::endsWith($path, ['.php', '.blade.php']);
        $isJs = Str::endsWith($path, '.js');

        foreach (preg_split('/\R/u', $content) ?: [] as $index => $line) {
            $matches = [];

            if ($isPhp) {
                if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/', $line, $matches)) {
                    $this->addSymbol($symbols, $seen, $matches[1], $matches[2], $index + 1);
                }

                if (preg_match('/^\s*(?:(?:final|abstract)\s+)?(?:public|protected|private)\s+(?:static\s+)?function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $matches)) {
                    $this->addSymbol($symbols, $seen, 'method', $matches[1], $index + 1);
                } elseif (preg_match('/^\s*function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $matches)) {
                    $this->addSymbol($symbols, $seen, 'function', $matches[1], $index + 1);
                }

                if (preg_match_all('/Schema::(create|table)\(\s*[\'\"]([^\'\"]+)[\'\"]/', $line, $tableMatches, PREG_SET_ORDER)) {
                    foreach ($tableMatches as $tableMatch) {
                        $this->addSymbol($symbols, $seen, 'table', $tableMatch[2], $index + 1);
                    }
                }
            }

            if ($isJs) {
                if (preg_match('/^\s*(?:export\s+)?(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $line, $matches)) {
                    $this->addSymbol($symbols, $seen, 'function', $matches[1], $index + 1);
                } elseif (preg_match('/^\s*(?:export\s+)?const\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:async\s*)?\(/', $line, $matches)) {
                    $this->addSymbol($symbols, $seen, 'function', $matches[1], $index + 1);
                }
            }
        }

        return $symbols;
    }

    /**
     * @param  list<array{type:string,name:string,line:int}>  $symbols
     * @param  array<string, bool>  $seen
     */
    protected function addSymbol(array &$symbols, array &$seen, string $type, string $name, int $line): void
    {
        $key = $type.'|'.$name.'|'.$line;

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $symbols[] = compact('type', 'name', 'line');
    }

    /**
     * @return list<array{methods:string,uri:string,name:string,action:string,middleware:string}>
     */
    protected function routeRecords(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->map(function (Route $route): array {
                return [
                    'methods' => implode('|', array_values(array_diff($route->methods(), ['HEAD']))),
                    'uri' => '/'.ltrim($route->uri(), '/'),
                    'name' => $route->getName() ?: '—',
                    'action' => $route->getActionName(),
                    'middleware' => collect($route->gatherMiddleware())
                        ->map(fn (string $middleware): string => $this->shortMiddlewareName($middleware))
                        ->implode(', '),
                ];
            })
            ->sortBy([['uri', 'asc'], ['methods', 'asc']])
            ->values()
            ->all();
    }

    protected function shortMiddlewareName(string $middleware): string
    {
        [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, null);
        $shortName = str_contains($name, '\\') ? class_basename($name) : $name;

        return $parameters === null ? $shortName : $shortName.':'.$parameters;
    }

    /**
     * @param  Collection<int, array{path:string,category:string,lines:int,symbols:list<array{type:string,name:string,line:int}>,content:string}>  $records
     * @param  list<array{methods:string,uri:string,name:string,action:string,middleware:string}>  $routes
     */
    protected function renderMarkdown($records, array $routes, string $outputPath): string
    {
        $symbolCount = $records->sum(fn (array $record): int => count($record['symbols']));
        $lines = [
            '# AlKhair Codebase Index',
            '',
            '> Generated by `php artisan codebase:index`. Regenerate it after structural code changes.',
            '',
            '## Fast search',
            '',
            '```bash',
            'php artisan codebase:search "assessment score"',
            'php artisan codebase:search "student attendance" --limit=50',
            'php artisan codebase:index',
            '```',
            '',
            'The search command uses the local FTS5 database at `storage/app/private/codebase-search.sqlite` and returns ranked `file:line` results. It supports English and Arabic terms.',
            '',
            '## Summary',
            '',
            '| Source files | Indexed symbols | Runtime routes |',
            '| ---: | ---: | ---: |',
            '| '.number_format($records->count()).' | '.number_format($symbolCount).' | '.number_format(count($routes)).' |',
            '',
            '## Runtime routes',
            '',
            '| Methods | URI | Name | Action | Middleware |',
            '| --- | --- | --- | --- | --- |',
        ];

        foreach ($routes as $route) {
            $lines[] = '| '.$this->markdownCell($route['methods'])
                .' | `'.$this->markdownCell($route['uri']).'`'
                .' | `'.$this->markdownCell($route['name']).'`'
                .' | `'.$this->markdownCell($route['action']).'`'
                .' | '.$this->markdownCell($route['middleware']).' |';
        }

        $lines[] = '';
        $lines[] = '## Source map';
        $lines[] = '';

        foreach ($records->groupBy('category')->sortKeys() as $category => $categoryRecords) {
            $lines[] = '### '.$category;
            $lines[] = '';

            foreach ($categoryRecords as $record) {
                $relativeLink = $this->relativeLink(dirname($outputPath), base_path($record['path']));
                $lines[] = '- [`'.$record['path'].'`](<'.$relativeLink.'>) — '.number_format($record['lines']).' lines';

                foreach ($record['symbols'] as $symbol) {
                    $lines[] = '  - `'.$symbol['type'].' '.$symbol['name'].'` — L'.$symbol['line'];
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    protected function markdownCell(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $value);
    }

    protected function relativeLink(string $fromDirectory, string $toPath): string
    {
        $from = explode('/', trim(str_replace('\\', '/', $fromDirectory), '/'));
        $to = explode('/', trim(str_replace('\\', '/', $toPath), '/'));

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)).implode('/', $to);
    }

    /**
     * @param  Collection<int, array{path:string,category:string,lines:int,symbols:list<array{type:string,name:string,line:int}>,content:string}>  $records
     * @param  list<array{methods:string,uri:string,name:string,action:string,middleware:string}>  $routes
     */
    protected function writeSearchDatabase(string $databasePath, $records, array $routes): void
    {
        File::ensureDirectoryExists(dirname($databasePath));

        if (File::exists($databasePath)) {
            File::delete($databasePath);
        }

        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->exec("CREATE VIRTUAL TABLE code_search USING fts5(
            kind UNINDEXED,
            path,
            category,
            symbols,
            content,
            tokenize = 'unicode61 remove_diacritics 2'
        )");

        $insert = $pdo->prepare('INSERT INTO code_search (kind, path, category, symbols, content) VALUES (?, ?, ?, ?, ?)');
        $pdo->beginTransaction();

        foreach ($records as $record) {
            $symbols = collect($record['symbols'])
                ->map(fn (array $symbol): string => $symbol['type'].' '.$symbol['name'].' line '.$symbol['line'])
                ->implode("\n");
            $insert->execute(['file', $record['path'], $record['category'], $symbols, $record['content']]);
        }

        foreach ($routes as $route) {
            $routeText = implode(' ', $route);
            $insert->execute([
                'route',
                $route['methods'].' '.$route['uri'],
                'Runtime route',
                $route['name'].' '.$route['action'],
                $routeText,
            ]);
        }

        $pdo->commit();
        $pdo->exec('INSERT INTO code_search(code_search) VALUES (\'optimize\')');
    }
}
