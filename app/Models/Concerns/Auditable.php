<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;

/**
 * Automatically records create / update / archive (soft delete) / restore events
 * for any model that uses it. Only the fields that actually changed are stored,
 * as before → after pairs. Secrets (passwords, PINs) are never written to the log.
 *
 * Models customise behaviour with:
 *   protected string $auditModule = 'products';
 *   public function auditLabel(): string   // human name used in descriptions
 */
trait Auditable
{
    /** Set to false (e.g. inside seeders) to suppress automatic logging. */
    public static bool $auditingEnabled = true;

    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->writeAudit('created', null, $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(function ($model) {
            $changes = $model->auditableAttributes($model->getChanges());

            // Restoring only touches deleted_at — the "restored" event below covers it.
            unset($changes['deleted_at']);
            if (empty($changes)) {
                return;
            }

            $old = array_intersect_key($model->auditableAttributes($model->getOriginal()), $changes);
            $model->writeAudit('updated', $old, $changes);
        });

        static::deleted(function ($model) {
            $event = method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting() ? 'archived' : 'deleted';
            $model->writeAudit($event);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => $model->writeAudit('restored'));
        }
    }

    protected function writeAudit(string $event, ?array $old = null, ?array $new = null): void
    {
        if (! static::$auditingEnabled) {
            return;
        }

        $verb = match ($event) {
            'created' => 'Created',
            'updated' => 'Updated',
            'archived' => 'Archived',
            'restored' => 'Restored',
            default => ucfirst($event),
        };

        AuditLogger::log(
            module: $this->auditModule ?? 'system',
            event: $event,
            description: "{$verb} {$this->auditEntityName()} \"{$this->auditLabel()}\"",
            subject: $this,
            old: $old,
            new: $new,
        );
    }

    /** Remove timestamps and secrets; flag secret changes without revealing values. */
    protected function auditableAttributes(array $attributes): array
    {
        $hidden = ['password', 'pin', 'remember_token'];
        $ignored = ['created_at', 'updated_at'];

        foreach ($hidden as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $attributes[$key] === null ? null : '••••••';
            }
        }

        return array_diff_key($attributes, array_flip($ignored));
    }

    public function auditLabel(): string
    {
        return (string) ($this->name ?? $this->reference_no ?? $this->order_number ?? '#'.$this->getKey());
    }

    protected function auditEntityName(): string
    {
        return strtolower(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($this))));
    }
}
