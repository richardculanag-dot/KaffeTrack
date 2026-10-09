<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Audit logs')]
class AuditLogs extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $module = '';

    #[Url]
    public string $user = '';

    #[Url]
    public string $event = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public $viewingId = null;

    public function mount(): void
    {
        $this->from = $this->from ?: today()->subDays(6)->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function updating($name): void
    {
        if ($name !== 'viewingId') {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'module', 'user', 'event');
        $this->from = today()->subDays(6)->toDateString();
        $this->to = today()->toDateString();
    }

    public function render()
    {
        $logs = AuditLog::query()
            ->whereBetween('created_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->when($this->module, fn ($q) => $q->where('module', $this->module))
            ->when($this->user, fn ($q) => $q->where('user_id', $this->user))
            ->when($this->event, fn ($q) => $q->where('event', $this->event))
            ->when($this->search, fn ($q) => $q->whereRaw('lower(description) like ?', ['%'.mb_strtolower($this->search).'%']))
            ->latest('created_at')->latest('id')
            ->paginate(25);

        return view('livewire.admin.audit-logs', [
            'logs' => $logs,
            'users' => User::withTrashed()->orderBy('name')->pluck('name', 'id'),
            'events' => AuditLog::query()->distinct()->orderBy('event')->pluck('event'),
            'viewing' => $this->viewingId ? AuditLog::find($this->viewingId) : null,
        ]);
    }
}
