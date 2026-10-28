<?php

namespace App\Services\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

final class ReferenceOptions
{
    public function rows(string $model): array
    {
        if (! in_array($model, [Branch::class, Department::class, TaskGroup::class], true)) {
            throw new InvalidArgumentException('Unsupported reference list.');
        }
        $load = fn (): array => $model::query()->orderBy('name')->get(
            $model === Department::class ? ['id', 'name', 'branch_id'] : ['id', 'name']
        )->toArray();
        if (! tenant('id') || ! config('cache.reference_enabled')) {
            return $load();
        }
        $key = 'tenant:'.hash('sha256', (string) tenant('id')).':'.class_basename($model);
        $store = null;
        try {
            $store = Cache::store('reference');
            $cached = $store->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // This cache is optional. Database failures below must still surface.
            $store = null;
        }
        $rows = $load();
        try {
            $store?->put($key, $rows, 30);
        } catch (Throwable) {
            // Missing Redis never prevents a form from loading.
        }

        return $rows;
    }
}
