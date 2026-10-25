<?php

// Install the downloaded export kit into the standalone V1 application first.
use App\Services\StandaloneTransfer\TransferExporter;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

require dirname(__DIR__).'/vendor/autoload.php';
$application = require dirname(__DIR__).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
config(['standalone-import' => require dirname(__DIR__).'/config/standalone-import.php']);
$console = new SymfonyStyle(new ArgvInput, new ConsoleOutput);
if (! $application->isDownForMaintenance()) {
    $console->error('Put standalone V1 in maintenance mode first. Do not run this against V2 or a live source.');
    exit(1);
}
if (! $console->confirm('Have all source queue workers, scheduler/cron tasks, integrations and other database/file writers been stopped? Keep them stopped until the export finishes.', false)) {
    exit(1);
}
$filename = $argv[1] ?? date('Ymd-His').'.alkhair-transfer';
if (strpbrk($filename, '/\\:') !== false || ! str_ends_with($filename, '.alkhair-transfer')) {
    $console->error('Supply an .alkhair-transfer filename, without a directory. Output is kept in private transfer storage.');
    exit(1);
}
$previousUmask = umask(0077);
$output = storage_path('app/tenant-transfer-exports/'.$filename);
$directory = dirname($output);
if (! is_dir($directory)) {
    mkdir($directory, 0700, true);
}
if (file_exists($output) || file_exists($output.'.transfer-code')) {
    $console->error('The output or code file already exists. Choose a new output path.');
    exit(1);
}
$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
try {
    set_time_limit(0);
    app(TransferExporter::class)->export($output, $secret);
    file_put_contents($output.'.transfer-code', $secret, LOCK_EX);
    chmod($output, 0600);
    chmod($output.'.transfer-code', 0600);
    $console->success('Export verified. Upload '.$output.' in Platform > Tenants > Import tenant. Read the adjacent .transfer-code file for the separate transfer code.');
    $console->note('This tool leaves V1 in maintenance mode. For a rehearsal, resume your source services deliberately. For final cutover, keep V1 read-only until the imported tenant is accepted.');
} catch (Throwable $exception) {
    $console->error('Export stopped: '.(in_array($exception->getMessage(), ['unsupported_schema', 'source_not_in_maintenance', 'unsupported_database', 'unsupported_public_assets', 'unsupported_storage_root', 'insufficient_scratch_space', 'unsafe_path', 'source_changed_during_export', 'row_too_large', 'package_too_large'], true) ? $exception->getMessage() : 'Check source compatibility, available disk space, and readable source files/encrypted fields.'));
    exit(1);
} finally {
    unset($secret);
    umask($previousUmask);
}
