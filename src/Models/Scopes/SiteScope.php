<?php

namespace mojosef\Leads\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SiteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $site = config('leads.site');

        if (! empty($site)) {
            $builder->where($model->qualifyColumn('site'), $site);
        }
    }
}
