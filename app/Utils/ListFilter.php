<?php

namespace App\Utils;

use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Filters shared by the backoffice list endpoints, which all sit behind
 * the same table component and its `filter[search]` box.
 */
class ListFilter
{
    /**
     * Case-insensitive substring match across the given columns.
     *
     * @param  list<string>  $columns
     */
    public static function search(array $columns): AllowedFilter
    {
        return AllowedFilter::callback('search', function (Builder $query, $value) use ($columns) {
            // The query builder splits comma-separated values into an array;
            // a search box means the whole typed text.
            $term = trim(is_array($value) ? implode(',', $value) : (string) $value);

            if ($term === '') {
                return;
            }

            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

            $query->where(function (Builder $inner) use ($columns, $pattern) {
                foreach ($columns as $column) {
                    $inner->orWhereRaw('LOWER('.$inner->getQuery()->getGrammar()->wrap($column).') LIKE ?', [$pattern]);
                }
            });
        });
    }
}
