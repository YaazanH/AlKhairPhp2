<?php

namespace App\Console\Commands;

use App\Models\SystemBackup;
use App\Services\SystemBackupService;
use Illuminate\Console\Command;
use Throwable;

class CreateSystemBackupCommand extends Command
{
    protected $signature = 'backup:run {--scheduled : Only create a database backup when the configured schedule is due} {--files : Manually back up uploaded files without the database}';

    protected $description = 'Create and verify an encrypted application backup';

    public function handle(SystemBackupService $backups): int
    {
        if ($this->option('scheduled') && $this->option('files')) {
            $this->error('Uploaded files can only be backed up manually.');

            return self::FAILURE;
        }

        try {
            $backup = $this->option('scheduled')
                ? $backups->runScheduled()
                : $backups->create(null, SystemBackup::TRIGGER_MANUAL, $this->option('files') ? SystemBackup::SCOPE_FILES : SystemBackup::SCOPE_DATABASE);

            if (! $backup) {
                $this->info(__('backups.commands.not_due'));

                return self::SUCCESS;
            }

            $this->info(__('backups.commands.created', ['filename' => $backup->filename]));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error(__('backups.commands.failed'));

            return self::FAILURE;
        }
    }
}
