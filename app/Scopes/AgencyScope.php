<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AgencyScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Only apply if there is an authenticated user with an agency_id.
        // This prevents breaking console commands or migrations.
        if (auth()->hasUser() && auth()->user()->agency_id) {
            $builder->where($model->getTable() . '.agency_id', auth()->user()->agency_id);
        }
    }
}