<?php

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class QueryFilters
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * Apply the shared listing conventions (search + sort) to a query.
     *
     * @param  array<int, string>  $searchable  Columns (or `relation.column`) searchable via ?search=
     * @param  array<int, string>  $sortable  Column names allowed in ?sort=
     */
    public static function apply(Builder $query, Request $request, array $searchable = [], array $sortable = [], string $defaultSort = '-id'): Builder
    {
        self::applySearch($query, $request, $searchable);
        self::applySort($query, $request, $sortable, $defaultSort);

        return $query;
    }

    public static function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /**
     * @param  array<int, string>  $searchable
     */
    protected static function applySearch(Builder $query, Request $request, array $searchable): void
    {
        $term = trim((string) $request->query('search', ''));

        if ($term === '' || $searchable === []) {
            return;
        }

        $query->where(function (Builder $q) use ($searchable, $term) {
            foreach ($searchable as $field) {
                if (str_contains($field, '.')) {
                    [$relation, $column] = explode('.', $field, 2);
                    $q->orWhereHas($relation, fn (Builder $r) => $r->where($column, 'like', "%{$term}%"));
                } else {
                    $q->orWhere($field, 'like', "%{$term}%");
                }
            }
        });
    }

    /**
     * @param  array<int, string>  $sortable
     */
    protected static function applySort(Builder $query, Request $request, array $sortable, string $defaultSort): void
    {
        $sort = (string) $request->query('sort', '');
        $field = ltrim($sort, '-');

        if ($sort === '' || ! in_array($field, $sortable, true)) {
            $sort = $defaultSort;
            $field = ltrim($sort, '-');
        }

        $query->orderBy($field, str_starts_with($sort, '-') ? 'desc' : 'asc');
    }
}
