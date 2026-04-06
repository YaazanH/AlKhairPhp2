<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected string $compiledViewPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiledViewPath = storage_path('framework/testing/views/'.Str::uuid());

        if (! is_dir($this->compiledViewPath)) {
            mkdir($this->compiledViewPath, 0777, true);
        }

        config()->set('view.compiled', $this->compiledViewPath);

        $bladeCompiler = app('blade.compiler');
        $cachePath = new \ReflectionProperty($bladeCompiler, 'cachePath');
        $cachePath->setAccessible(true);
        $cachePath->setValue($bladeCompiler, $this->compiledViewPath);
    }

    protected function tearDown(): void
    {
        if (isset($this->compiledViewPath) && is_dir($this->compiledViewPath)) {
            app('files')->deleteDirectory($this->compiledViewPath);
        }

        parent::tearDown();
    }
}
