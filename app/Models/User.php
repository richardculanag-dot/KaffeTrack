<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\NormalisesName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
    use NormalisesName, Auditable, Notifiable, SoftDeletes;

    public const ROLE_MANAGER = 'manager';
    public const ROLE_STAFF = 'staff';

    protected string $auditModule = 'users';

    protected $fillable = [
        'name', 'email', 'username', 'phone', 'role', 'password', 'pin', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password', 'pin', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** Logins are case-insensitive: emails and usernames are stored in lower case. */
    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    protected function username(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function checkPin(?string $pin): bool
    {
        return $this->isManager() && $this->pin && $pin && Hash::check($pin, $this->pin);
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))->filter()->take(2)
            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
