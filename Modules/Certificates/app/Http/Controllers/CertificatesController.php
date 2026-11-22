<?php

namespace Modules\Certificates\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Certificates\Http\Requests\StoreCertificateRequest;
use Modules\Certificates\Http\Requests\UpdateCertificateRequest;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Transformers\CertificateResource;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;

class CertificatesController extends ApiController
{
    // PUB: active certificates for the landing page (plain array, ordered).
    public function index(): JsonResponse
    {
        $certificates = Certificate::query()
            ->with('media')
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->success(CertificateResource::collection($certificates));
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Certificate::query()->with('media');
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('issuer', 'like', "%{$search}%"));
        }
        $query->when($request->query('status'), fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'));

        $certificates = $query->orderBy('sort_order')->orderBy('id')->paginate(QueryFilters::perPage($request));

        return $this->paginated(CertificateResource::collection($certificates));
    }

    public function show(Certificate $certificate): JsonResponse
    {
        return $this->success(CertificateResource::make($certificate->load('media')));
    }

    public function store(StoreCertificateRequest $request): JsonResponse
    {
        $data = $request->safe()->except('image');
        $certificate = Certificate::query()->create($data);
        if ($file = $request->file('image')) {
            $certificate->addMedia($file)->toMediaCollection('image');
        }

        return $this->created(CertificateResource::make($certificate), __('certificates::messages.created'));
    }

    public function update(UpdateCertificateRequest $request, Certificate $certificate): JsonResponse
    {
        $certificate->update($request->safe()->except('image'));
        if ($file = $request->file('image')) {
            $certificate->addMedia($file)->toMediaCollection('image');
        }

        return $this->success(CertificateResource::make($certificate->refresh()), __('certificates::messages.updated'));
    }

    public function destroy(Certificate $certificate): JsonResponse
    {
        $certificate->clearMediaCollection('image');
        $certificate->delete();

        return $this->noContent(__('certificates::messages.deleted'));
    }
}
