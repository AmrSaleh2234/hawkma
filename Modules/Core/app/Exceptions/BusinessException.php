<?php

namespace Modules\Core\Exceptions;

use Modules\Core\Enums\ErrorCode;
use RuntimeException;

class BusinessException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors  Field-level errors, if any.
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly int $status = 422,
        public readonly array $errors = [],
    ) {
        parent::__construct($message ?? __('core::errors.'.$errorCode->value));
    }
}
