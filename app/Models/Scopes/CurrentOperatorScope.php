<?php

namespace App\Models\Scopes;

use App\Support\CurrentOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CurrentOperatorScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $operatorId = CurrentOperator::id();

        if ($operatorId === null) {
            return;
        }

        $builder->where($model->getTable().'.operator_id', $operatorId);
    }
}
