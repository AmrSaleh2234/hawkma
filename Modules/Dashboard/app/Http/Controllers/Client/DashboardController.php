<?php

namespace Modules\Dashboard\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Dashboard\Services\ClientDashboardService;

class DashboardController extends ApiController
{
    /**
     * CLI-DSH-01 GET /api/v1/client/dashboard
     */
    public function show(Request $request, ClientDashboardService $dashboard): JsonResponse
    {
        return $this->success($dashboard->dashboardFor($request->user('client')));
    }
}
