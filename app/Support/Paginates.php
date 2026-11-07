<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * List endpoints: ?page=&per_page=&search= with the Laravel paginator shape
 * { data, meta: { current_page, last_page, per_page, total } }.
 */
trait Paginates
{
    /**
     * @param  callable(mixed): array  $present
     * @param  string|null  $legacyKey  also return the rows under the key older SPA builds read
     */
    protected function paginate(Request $request, Builder $query, callable $present, ?string $legacyKey = null): array
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $page = $query->paginate($perPage)->withQueryString();
        $rows = collect($page->items())->map($present)->values()->all();

        $out = [
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
        if ($legacyKey !== null) {
            $out[$legacyKey] = $rows;
        }

        return $out;
    }

    /** Case-insensitive "contains" over the given columns. */
    protected function search(Request $request, Builder $query, array $columns): Builder
    {
        $term = trim((string) $request->query('search', ''));
        if ($term === '') {
            return $query;
        }
        $like = '%'.str_replace('%', '', mb_substr($term, 0, 100)).'%';

        return $query->where(function ($q) use ($columns, $like) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', $like);
            }
        });
    }
}
