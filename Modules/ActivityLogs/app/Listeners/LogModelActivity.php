<?php

namespace Modules\ActivityLogs\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\ActivityLogs\Enums\ActivityEvent;
use Modules\ActivityLogs\Models\ActivityLog;
use Modules\ActivityLogs\Support\ActivityLogger;

/**
 * Wildcard listener for `eloquent.{created|updated|deleted|restored}: *`.
 * Only module models (Modules\*) are logged; framework/vendor models and
 * the log table itself are skipped.
 */
class LogModelActivity
{
    /**
     * @param  array<int, mixed>  $data
     */
    public function handle(string $eventName, array $data): void
    {
        $model = $data[0] ?? null;

        if (! $model instanceof Model || $model instanceof ActivityLog) {
            return;
        }

        if (! str_starts_with($model::class, 'Modules\\')) {
            return;
        }

        $event = match (Str::between($eventName, 'eloquent.', ':')) {
            'created' => ActivityEvent::Created,
            'updated' => ActivityEvent::Updated,
            'deleted' => ActivityEvent::Deleted,
            'restored' => ActivityEvent::Restored,
            default => null,
        };

        if ($event === null) {
            return;
        }

        $properties = match ($event) {
            ActivityEvent::Created => ['attributes' => ActivityLogger::sanitize($model->getAttributes())],
            ActivityEvent::Updated => $this->updatedProperties($model),
            ActivityEvent::Deleted => [
                'attributes' => ActivityLogger::sanitize($model->getAttributes()),
                'force' => $this->isForceDeleting($model),
            ],
            default => [],
        };

        // `null` means there was nothing meaningful to record.
        if ($properties === null) {
            return;
        }

        ActivityLogger::system($event, $model, $properties);
    }

    /**
     * Build the before/after diff. Returns null when the only changed keys
     * are housekeeping columns (updated_at, last_login_at, ...).
     *
     * @return array{changes: array<string, mixed>, old: array<string, mixed>}|null
     */
    private function updatedProperties(Model $model): ?array
    {
        $changes = $model->getChanges();

        if (array_diff(array_keys($changes), ActivityLogger::ignoredUpdateKeys()) === []) {
            return null;
        }

        $old = array_intersect_key($model->getOriginal(), $changes);

        return [
            'changes' => ActivityLogger::sanitize($changes),
            'old' => ActivityLogger::sanitize($old),
        ];
    }

    private function isForceDeleting(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true)
            && $model->isForceDeleting();
    }
}
