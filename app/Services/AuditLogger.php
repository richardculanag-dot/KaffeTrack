<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Central writer for the Audit / Logs module.
 * Model CRUD is logged automatically by the Auditable trait; domain actions
 * (login, stock out, refund, shift close …) call AuditLogger::log() explicitly.
 */
class AuditLogger
{
    public static function log(
        string $module,
        string $event,
        string $description,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?User $user = null,
    ): AuditLog {
        $user ??= Auth::user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'module' => $module,
            'event' => $event,
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'description' => Str::limit($description, 250),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
            'user_agent' => app()->runningInConsole() ? 'console' : Str::limit((string) Request::userAgent(), 250, ''),
            'created_at' => now(),
        ]);
    }
}
