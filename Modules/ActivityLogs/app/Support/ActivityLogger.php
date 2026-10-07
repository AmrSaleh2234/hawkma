<?php

namespace Modules\ActivityLogs\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\ActivityLogs\Enums\ActivityEvent;
use Modules\ActivityLogs\Models\ActivityLog;

final class ActivityLogger
{
    /**
     * Attribute keys that must never be persisted in a log entry.
     */
    private const SENSITIVE_PATTERN = '/password|token|secret/i';

    /**
     * Attributes that change as a side effect of other actions and do not
     * deserve their own `updated` entry.
     */
    private const IGNORED_UPDATE_KEYS = ['updated_at', 'last_login_at', 'remember_token'];

    /**
     * Record an API-level activity (auth events and similar entry points).
     *
     * @param  array<string, mixed>  $properties
     */
    public static function api(ActivityEvent $event, ?Model $causer = null, array $properties = [], string $module = 'auth'): ActivityLog
    {
        $guard = (string) ($properties['guard'] ?? 'api');
        $description = trim($guard.' '.str_replace('_', ' ', $event->value));

        return self::record(
            logName: ActivityLog::NAME_API,
            event: $event,
            module: $module,
            description: $description,
            causer: $causer,
            properties: $properties,
        );
    }

    /**
     * Record a system-level activity (model CRUD).
     *
     * @param  array<string, mixed>  $properties
     */
    public static function system(
        ActivityEvent $event,
        ?Model $subject = null,
        array $properties = [],
        ?string $description = null,
    ): ActivityLog {
        $module = self::moduleOf($subject);
        $description ??= $subject === null
            ? $event->value
            : class_basename($subject).' '.$event->value;

        return self::record(
            logName: ActivityLog::NAME_SYSTEM,
            event: $event,
            module: $module,
            description: $description,
            subject: $subject,
            causer: self::resolveCauser(),
            properties: $properties,
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $logName,
        ActivityEvent $event,
        string $module,
        string $description,
        ?Model $subject = null,
        ?Model $causer = null,
        array $properties = [],
    ): ActivityLog {
        if ($request = self::httpRequest()) {
            $properties['request'] = array_filter([
                'method' => $request->method(),
                'url' => $request->path(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        $log = new ActivityLog;
        $log->forceFill([
            'log_name' => $logName,
            'module' => $module,
            'event' => $event->value,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => self::httpRequest()?->ip(),
        ]);
        $log->saveQuietly();

        return $log;
    }

    /**
     * The current actor on either API guard, or null for console/queue work.
     */
    public static function resolveCauser(): ?Model
    {
        foreach (['admin', 'client'] as $guard) {
            try {
                if ($user = Auth::guard($guard)->user()) {
                    return $user instanceof Model ? $user : null;
                }
            } catch (\Throwable) {
                // Guard unavailable in this context (console, queue, ...).
            }
        }

        return null;
    }

    /**
     * The module name (lowercase, e.g. `consultants`) owning a model class,
     * or `app` for non-module classes.
     */
    public static function moduleOf(?Model $model): string
    {
        if ($model === null) {
            return 'system';
        }

        $class = $model::class;
        if (preg_match('/^Modules\\\\([^\\\\]+)\\\\/', $class, $m) === 1) {
            return strtolower($m[1]);
        }

        return 'app';
    }

    /**
     * Strip sensitive keys from an attribute array before persisting.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function sanitize(array $attributes): array
    {
        return collect($attributes)
            ->reject(fn (mixed $value, string $key): bool => (bool) preg_match(self::SENSITIVE_PATTERN, $key))
            ->all();
    }

    /**
     * Keys that never justify an `updated` entry on their own.
     *
     * @return array<int, string>
     */
    public static function ignoredUpdateKeys(): array
    {
        return self::IGNORED_UPDATE_KEYS;
    }

    /**
     * The request currently being served, or null in console/queue/seeders.
     * `$request->route()` is null until the router has matched a route, which
     * is also what distinguishes the empty request bound for artisan runs.
     * (runningInConsole() cannot be used: it is true inside HTTP tests.)
     */
    private static function httpRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');
        if (! $request instanceof Request || $request->route() === null) {
            return null;
        }

        return $request;
    }
}
