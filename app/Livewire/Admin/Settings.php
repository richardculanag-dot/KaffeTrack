<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\WithToasts;
use App\Models\Order;
use App\Models\Setting;
use App\Services\AuditLogger;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Settings')]
class Settings extends Component
{
    use WithToasts;

    public array $form = [];

    /** The settings this screen edits — each one is validated in save(). currency_symbol is fixed. */
    private const EDITABLE = ['store_name', 'store_address', 'store_contact', 'store_tin', 'vat_registered', 'vat_rate',
        'receipt_footer', 'table_count'];

    public function mount(): void
    {
        foreach (self::EDITABLE as $key) {
            $this->form[$key] = Setting::get($key);
        }
        $this->form['vat_registered'] = Setting::bool('vat_registered');
    }

    public function save(): void
    {
        $this->validate([
            'form.store_name' => ['required', 'string', 'max:100'],
            'form.store_address' => ['required', 'string', 'max:255'],
            'form.store_contact' => ['nullable', 'string', 'max:100'],
            'form.store_tin' => ['nullable', 'string', 'max:30', 'regex:/^[0-9\-]*$/'],
            'form.vat_registered' => ['boolean'],
            'form.vat_rate' => ['required', 'numeric', 'min:0', 'max:30'],
            'form.receipt_footer' => ['nullable', 'string', 'max:200'],
            'form.table_count' => ['required', 'integer', 'between:1,'.Order::MAX_TABLES],
        ], ['form.store_tin.regex' => 'Use digits and dashes only.'], [
            'form.store_name' => 'store name', 'form.store_address' => 'address', 'form.vat_rate' => 'VAT rate', 'form.store_tin' => 'TIN',
            'form.table_count' => 'number of tables',
        ]);

        $changed = [];
        Setting::$auditingEnabled = false; // one summarised audit entry instead of one per key
        // Only known settings are saved. $form is public, so a crafted request could add extra keys
        // (e.g. via $wire.set('form.anything', ...)); those are ignored instead of written to the table.
        foreach (self::EDITABLE as $key) {
            $value = $this->form[$key] ?? null;
            $old = $key === 'vat_registered' ? Setting::bool($key) : Setting::get($key);
            if ((string) $old !== (string) $value) {
                $changed[$key] = ['old' => $old, 'new' => $value];
            }
            Setting::put($key, $value ?? '');
        }
        Setting::$auditingEnabled = true;

        if ($changed) {
            AuditLogger::log('settings', 'settings_updated', 'Updated store settings: '.implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), array_keys($changed))),
                null, array_map(fn ($c) => $c['old'], $changed), array_map(fn ($c) => $c['new'], $changed));
            $this->toast('Settings saved.');
        } else {
            $this->toast('Nothing changed.', 'info');
        }
    }

    public function render()
    {
        return view('livewire.admin.settings');
    }
}
