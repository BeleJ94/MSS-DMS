<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MailSettingsValidationException extends RuntimeException
{
    public $field;

    public function __construct(string $field, string $message)
    {
        parent::__construct($message);
        $this->field = $field;
    }
}
