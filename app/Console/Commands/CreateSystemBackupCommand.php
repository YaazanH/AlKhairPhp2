<?php

namespace App\Console\Commands;

use App\Models\SystemBackup;
use App\Services\SystemBackupService;
use Illuminate\Console\Command;
use Throwable;

class CreateSystemBackupCommand extends Command
{
    protected $signature = 'backup:run {--scheduled : Only create a backup when the configured schedule is due}';

    protected $description = 'Create and verify an encrypted application backup';

    public function handle(SystemBackupService $backups): int
    {
        try {
            $backup = $this->option('scheduled')
                ? $backups->runScheduled()
                : $backups->create(null, SystemBackup::TRIGGER_MANUAL);

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
