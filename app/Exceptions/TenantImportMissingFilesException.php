<?php

namespace App\Exceptions;

use RuntimeException;

class TenantImportMissingFilesException extends RuntimeException
{
    public function __construct(public readonly array $files, public readonly int $total)
    {
        parent::__construct('missing_file');
    }
}
