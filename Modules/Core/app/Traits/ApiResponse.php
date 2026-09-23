<?php

namespace Modules\Core\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function created(mixed $data, string $message): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    protected function paginated(ResourceCollection|LengthAwarePaginator $collection, string $message = 'OK'): JsonResponse
    {
        if ($collection instanceof ResourceCollection) {
            $paginator = $collection->resource;
            $data = $collection->toArray(request());
        } else {
            $paginator = $collection;
            $data = $collection->items();
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url(max(1, $paginator->lastPage())),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    protected function noContent(string $message): JsonResponse
    {
        return $this->success(null, $message);
    }
}
