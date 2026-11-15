<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\Setting;

/**
 * Central, cached accessor for the key/value `settings` table (platform
 * fee percentage, payout automation toggle, ...).
 *
 * The keys are constants so nothing else hard-codes the strings.
 */
final class Settings
{
    public const PLATFORM_FEE_PERCENT = 'platform_fee_percent';

    public const PAYOUT_AUTO_TRANSFER = 'payout_auto_transfer';

    /** Default global platform fee percentage when nothing is saved yet. */
    public const DEFAULT_PLATFORM_FEE_PERCENT = 15;

    /**
     * Read a setting as a string (or the default when it is not saved).
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $cacheKey = "settings.{$key}";

        return Cache::rememberForever($cacheKey, function () use ($key, $default) {
            return Setting::query()->where('key', $key)->value('value') ?? $default;
        });
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, (string) $default);
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        return (float) self::get($key, (string) $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        return filter_var(self::get($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Upsert a setting and drop its cache entry.
     */
    public static function set(string $key, string|int|float|bool|null $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? 'true' : 'false') : ($value === null ? null : (string) $value)],
        );

        Cache::forget("settings.{$key}");
    }

    /**
     * All settings the admin UI is allowed to read/write. The frontend
     * uses this contract so nothing else can be toggled.
     *
     * @return array<string, mixed>
     */
    public static function editable(): array
    {
        return [
            self::PLATFORM_FEE_PERCENT => self::getFloat(self::PLATFORM_FEE_PERCENT, (float) self::DEFAULT_PLATFORM_FEE_PERCENT),
            self::PAYOUT_AUTO_TRANSFER => self::getBool(self::PAYOUT_AUTO_TRANSFER, false),
        ];
    }
}
