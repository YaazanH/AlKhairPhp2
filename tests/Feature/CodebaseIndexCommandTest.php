<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CodebaseIndexCommandTest extends TestCase
{
    public function test_it_generates_and_searches_the_first_party_codebase_index(): void
    {
        $map = 'storage/framework/testing/codebase-index.md';
        $database = 'storage/framework/testing/codebase-search.sqlite';

        File::delete([base_path($map), base_path($database)]);

        try {
            $this->artisan('codebase:index', [
                '--output' => $map,
                '--database' => $database,
            ])->assertSuccessful();

            $this->assertFileExists(base_path($map));
            $this->assertFileExists(base_path($database));
            $this->assertStringContainsString('# AlKhair Codebase Index', File::get(base_path($map)));
            $this->assertStringContainsString('app/Console/Commands/CodebaseIndexCommand.php', File::get(base_path($map)));
            $this->assertStringContainsString('## Runtime routes', File::get(base_path($map)));

            $this->artisan('codebase:search', [
                'query' => 'CodebaseIndexCommand',
                '--database' => $database,
            ])
                ->expectsOutputToContain('app/Console/Commands/CodebaseIndexCommand.php')
                ->assertSuccessful();
        } finally {
            File::delete([base_path($map), base_path($database)]);
        }
    }
}
