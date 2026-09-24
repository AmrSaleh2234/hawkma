<?php

namespace Modules\Dashboard\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Dashboard\Services\AdminStatsService;

class DashboardController extends ApiController
{
    /**
     * DSH-01 GET /api/v1/admin/dashboard/stats — Perm: view-dashboard
     */
    public function stats(Request $request, AdminStatsService $stats): JsonResponse
    {
        return $this->success($stats->statsFor($request->user('admin')));
    }
}
