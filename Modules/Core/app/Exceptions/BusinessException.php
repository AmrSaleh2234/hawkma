<?php

namespace Modules\Core\Exceptions;

use Modules\Core\Enums\ErrorCode;
use RuntimeException;

class BusinessException extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly int $status = 422,
    ) {
        parent::__construct($message ?? __('core::errors.'.$errorCode->value));
    }
}
