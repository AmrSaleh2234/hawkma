<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Modules\Core\Traits\ApiResponse;

abstract class ApiController
{
    use ApiResponse;
    use AuthorizesRequests;
}
