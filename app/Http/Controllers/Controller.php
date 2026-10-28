<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Order and paginate a list endpoint from ?sort, ?direction and ?per_page.
     *
     * $sortable maps the sort keys a client may send to the columns they order by. A
     * value may be a column, a raw expression, or a list of them applied in order (so
     * 'weekday' can mean weekday then start_time).
     * Anything else falls back to $defaultSort, so a request can never order by an
     * arbitrary column. Rows are tie-broken by id so pages stay stable under equal keys.
     */
    protected function sortAndPaginate(Request $request, $query, array $sortable, string $defaultSort, string $defaultDirection = 'desc', int $defaultPerPage = 25)
    {
        $requested = (string) $request->query('sort');
        $known = array_key_exists($requested, $sortable);
        $column = $known ? $sortable[$requested] : $sortable[$defaultSort];

        // The requested direction applies only to a recognised sort key; an unknown key
        // yields the default ordering in full, so a stray parameter cannot flip the list.
        $direction = strtolower((string) $request->query('direction', $defaultDirection));
        if (!$known || !in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        $columns = is_array($column) ? $column : [$column];
        foreach ($columns as $orderColumn) {
            $query->orderBy($orderColumn, $direction);
        }
        if ($columns[0] !== 'id') {
            $query->orderBy('id', 'desc');
        }

        return $query->paginate(min(max((int) $request->query('per_page', $defaultPerPage), 1), 100));
    }
}
