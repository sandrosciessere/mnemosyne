<?php

namespace App\Models;

use App\Enums\IngestionEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    public const SUBMISSION_AUTO_APPROVAL = 'submission_auto_approval';

    public const INGESTION_PAUSED = 'ingestion_paused';

    protected $guarded = ['*'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cached = Cache::remember(
            "system_setting:{$key}",
            300,
            fn () => static::query()->where('key', $key)->first()?->value ?? ['__missing' => true],
        );

        if (is_array($cached) && array_key_exists('__missing', $cached)) {
            return $default;
        }

        // Values are stored wrapped as ['value' => ...] so scalars survive
        // the JSON column round trip unambiguously.
        return is_array($cached) && array_key_exists('value', $cached) ? $cached['value'] : $default;
    }

    public static function set(string $key, mixed $value, ?User $actor = null): void
    {
        $setting = static::query()->where('key', $key)->first() ?? new static;
        $setting->forceFill([
            'key' => $key,
            'value' => ['value' => $value],
            'updated_by' => $actor?->id,
        ])->save();

        Cache::forget("system_setting:{$key}");

        IngestionEvent::record(IngestionEventType::SettingChanged, actor: $actor, payload: [
            'key' => $key,
            'value' => $value,
        ]);
    }

    public static function autoApprovalEnabled(): bool
    {
        return (bool) static::get(self::SUBMISSION_AUTO_APPROVAL, false);
    }

    /** Global cooperative ingestion pause (survives restarts: persisted). */
    public static function ingestionPaused(): bool
    {
        return (bool) static::get(self::INGESTION_PAUSED, false);
    }
}
