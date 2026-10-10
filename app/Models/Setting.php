<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use Auditable;

    protected string $auditModule = 'settings';

    protected $fillable = ['key', 'value', 'type'];

    public const DEFAULTS = [
        'store_name' => 'HEIM Café',
        'store_address' => 'Davao City, Philippines',
        'store_contact' => '',
        'store_tin' => '',
        'vat_registered' => '1',
        'vat_rate' => '12',
        'receipt_footer' => 'Thank you for visiting HEIM! Please come again.',
        'currency_symbol' => '₱',
        'table_count' => '10',
    ];

    /** Read a setting (cached for the request lifecycle + 10 minutes). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::remember('settings.all', 600, fn () => static::query()->pluck('value', 'key')->all());

        return $all[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function bool(string $key): bool
    {
        return filter_var(static::get($key), FILTER_VALIDATE_BOOLEAN);
    }

    public static function put(string $key, mixed $value): void
    {
        $setting = static::firstOrNew(['key' => $key]);
        $setting->value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $setting->save();
        Cache::forget('settings.all');
    }

    public function auditLabel(): string
    {
        return $this->key;
    }
}
