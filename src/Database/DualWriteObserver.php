<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Database;

use Illuminate\Database\Eloquent\Model;
use Nanayawkumi\NykDbSync\DualWrite;

class DualWriteObserver
{
    public function __construct(protected DualWrite $dualWrite) {}

    public function saved(Model $model): void
    {
        $this->dualWrite->replicateModel($model);
    }

    public function deleted(Model $model): void
    {
        if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting() && method_exists($model, 'trashed') && $model->trashed()) {
            $this->dualWrite->replicateModel($model);

            return;
        }

        $this->dualWrite->deleteModel($model);
    }

    public function forceDeleted(Model $model): void
    {
        $this->dualWrite->deleteModel($model);
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<int|string, array<string, mixed>>  $attributes
     */
    public function pivotAttached(Model $model, string $relationName, array $ids, array $attributes): void
    {
        $this->dualWrite->replicatePivot($model, $relationName, $ids, $attributes);
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function pivotDetached(Model $model, string $relationName, array $ids): void
    {
        $this->dualWrite->deletePivot($model, $relationName, $ids);
    }
}
