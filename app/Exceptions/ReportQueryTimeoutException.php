<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ReportQueryTimeoutException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The report query exceeded its configured execution time.', 0, $previous);
    }

    public function userMessage(): string
    {
        return __('report_designer.errors.query_timeout');
    }
}
