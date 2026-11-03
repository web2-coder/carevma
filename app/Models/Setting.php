<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Key/value application settings; each value is a JSON blob, cached until it is written. */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];
    protected $casts = ['value' => 'array'];

    public static function fetch(string $key, array $default = []): array
    {
        $stored = Cache::rememberForever("setting:{$key}", fn () => ['value' => static::find($key)?->value]);

        return array_merge($default, is_array($stored['value'] ?? null) ? $stored['value'] : []);
    }

    public static function put(string $key, array $value): array
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:{$key}");

        return $value;
    }
}
