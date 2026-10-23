<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Pessimistic row lock inside the current transaction, returning the row as
 * read under the lock. SQLite ignores FOR UPDATE, so a no-op write takes the
 * database write lock instead (other writers wait up to busy_timeout).
 */
final class RowLock
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    public static function lock(string $model, int $id): Model
    {
        if (DB::getDriverName() === 'sqlite') {
            $instance = new $model;
            DB::table($instance->getTable())
                ->where($instance->getKeyName(), $id)
                ->update([$instance->getUpdatedAtColumn() => DB::raw($instance->getUpdatedAtColumn())]);

            return $model::query()->whereKey($id)->firstOrFail();
        }

        return $model::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
