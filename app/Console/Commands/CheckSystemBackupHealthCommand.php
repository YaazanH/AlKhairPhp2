<?php

namespace App\Console\Commands;

use App\Services\SystemBackupService;
use Illuminate\Console\Command;

class CheckSystemBackupHealthCommand extends Command
{
    protected $signature = 'backup:health';

    protected $description = 'Check backup freshness, failures, and local free space';

    public function handle(SystemBackupService $backups): int
    {
        $health = $backups->health();

        if ($health['warnings'] === []) {
            $this->info(__('backups.commands.healthy'));

            return self::SUCCESS;
        }

        foreach ($health['warnings'] as $warning) {
            $this->warn(__('backups.health.warnings.'.$warning));
        }

        return self::FAILURE;
    }
}
