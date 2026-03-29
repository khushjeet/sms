<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SchoolSetting extends Model
{
    private const CACHE_KEY = 'school_settings.map';

    protected $table = 'school_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        if (!static::settingsTableExists()) {
            return $default;
        }

        return static::getValues([$key])[$key] ?? $default;
    }

    public static function getValues(array $keys): array
    {
        if (!static::settingsTableExists()) {
            return collect($keys)->mapWithKeys(fn (string $key) => [$key => null])->all();
        }

        $settings = Cache::rememberForever(self::CACHE_KEY, function () {
            return static::query()
                ->get(['key', 'value'])
                ->pluck('value', 'key')
                ->all();
        });

        return collect($keys)
            ->mapWithKeys(fn (string $key) => [$key => $settings[$key] ?? null])
            ->all();
    }

    public static function putValue(string $key, ?string $value): void
    {
        if (!static::settingsTableExists()) {
            return;
        }

        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget(self::CACHE_KEY);
    }

    private static function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable((new static())->getTable());
        } catch (\Throwable) {
            return false;
        }
    }
}
