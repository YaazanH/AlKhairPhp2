<?php

namespace App\Exceptions;

use RuntimeException;

class BackupDatabaseMismatchException extends RuntimeException
{
    public function __construct(public readonly string $backupDriver, public readonly string $localDriver)
    {
        parent::__construct('The backup database driver does not match this installation.');
    }

    public function userMessage(): string
    {
        return __('backups.errors.database_mismatch', [
            'backup' => strtoupper($this->backupDriver),
            'local' => strtoupper($this->localDriver),
        ]);
    }
}
