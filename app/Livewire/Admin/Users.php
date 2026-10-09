<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\WithToasts;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Users')]
class Users extends Component
{
    use WithToasts;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $role = '';

    public bool $showForm = false;
    public ?int $editingId = null;
    public array $form = [];

    public bool $showPassword = false;
    public ?int $passwordUserId = null;
    public string $newPassword = '';
    public string $newPassword_confirmation = '';

    private function blank(): array
    {
        return ['name' => '', 'username' => '', 'email' => '', 'phone' => '', 'role' => 'staff', 'password' => '', 'password_confirmation' => '', 'pin' => '', 'is_active' => true];
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->blank();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $u = User::findOrFail($id);
        $this->editingId = $id;
        $this->form = ['name' => $u->name, 'username' => $u->username, 'email' => $u->email, 'phone' => $u->phone ?? '', 'role' => $u->role,
            'password' => '', 'password_confirmation' => '', 'pin' => '', 'is_active' => $u->is_active];
        $this->showForm = true;
    }

    public function save(): void
    {
        $isSelf = $this->editingId === auth()->id();
        // Logins are case-insensitive; normalise before the uniqueness check so it matches the DB index.
        $this->form['email'] = mb_strtolower(trim($this->form['email'] ?? ''));
        $this->form['username'] = mb_strtolower(trim($this->form['username'] ?? ''));

        $rules = [
            'form.name' => ['required', 'string', 'max:150'],
            'form.username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($this->editingId)->whereNull('deleted_at')],
            'form.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)->whereNull('deleted_at')],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.role' => ['required', Rule::in([User::ROLE_MANAGER, User::ROLE_STAFF])],
            'form.is_active' => ['boolean'],
            'form.pin' => [$this->form['role'] === 'manager' && ! $this->editingId ? 'required' : 'nullable', 'digits_between:4,6'],
        ];
        if (! $this->editingId) {
            $rules['form.password'] = ['required', 'confirmed', Password::min(8)->letters()->numbers()];
        }

        $this->validate($rules, [
            'form.username.regex' => 'Use letters, numbers, dots, dashes or underscores only.',
            'form.pin.required' => 'Managers need a PIN to approve refunds.',
            'form.pin.digits_between' => 'The PIN must be 4 to 6 digits.',
        ], [
            'form.name' => 'full name', 'form.username' => 'username', 'form.email' => 'email', 'form.password' => 'password', 'form.pin' => 'PIN',
        ]);

        // Guard rails: you can't lock yourself out, and there must always be an active manager.
        if ($isSelf && (! $this->form['is_active'] || $this->form['role'] !== 'manager')) {
            $this->addError('form.role', 'You cannot deactivate yourself or remove your own manager role.');

            return;
        }

        $data = collect($this->form)->only(['name', 'username', 'email', 'phone', 'role', 'is_active'])->all();
        $data['phone'] = $data['phone'] ?: null;
        if (filled($this->form['pin']) && $this->form['role'] === 'manager') {
            $data['pin'] = $this->form['pin'];
        }
        if ($this->form['role'] === 'staff') {
            $data['pin'] = null;
        }

        if ($this->editingId) {
            User::findOrFail($this->editingId)->update($data);
            $this->toast("{$data['name']}'s account updated.");
        } else {
            User::create($data + ['password' => $this->form['password']]);
            $this->toast("Account created for {$data['name']}. Share the username and password with them privately.");
        }
        $this->showForm = false;
    }

    public function openPassword(int $id): void
    {
        $this->resetValidation();
        $this->passwordUserId = $id;
        $this->newPassword = $this->newPassword_confirmation = '';
        $this->showPassword = true;
    }

    public function resetPassword(): void
    {
        $this->validate(['newPassword' => ['required', 'confirmed', Password::min(8)->letters()->numbers()]], [], ['newPassword' => 'new password']);
        $user = User::findOrFail($this->passwordUserId);
        $user->update(['password' => $this->newPassword]);
        AuditLogger::log('users', 'password_reset', "Reset the password of {$user->name}", $user);
        $this->showPassword = false;
        $this->toast("Password reset for {$user->name}.");
    }

    public function toggleActive(int $id): void
    {
        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            $this->toast('You cannot deactivate your own account.', 'error');

            return;
        }
        if ($user->is_active && $user->isManager() && User::active()->where('role', 'manager')->count() <= 1) {
            $this->toast('There must be at least one active manager.', 'error');

            return;
        }
        $user->update(['is_active' => ! $user->is_active]);
        $this->toast($user->is_active ? "{$user->name} can sign in again." : "{$user->name} is deactivated and will be signed out.");
    }

    public function archive(int $id): void
    {
        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            $this->toast('You cannot archive your own account.', 'error');

            return;
        }
        if ($user->isManager() && User::where('role', 'manager')->count() <= 1) {
            $this->toast('You cannot archive the last manager.', 'error');

            return;
        }
        $user->delete();
        $this->toast("{$user->name}'s account archived. Their history is kept in all records.");
    }

    public function render()
    {
        return view('livewire.admin.users', [
            'users' => User::query()
                ->withCount(['orders' => fn ($q) => $q->where('created_at', '>=', today()->subDays(29))])
                ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', ['%'.mb_strtolower($this->search).'%'])
                    ->orWhere('username', 'like', '%'.mb_strtolower($this->search).'%')->orWhere('email', 'like', '%'.mb_strtolower($this->search).'%')))
                ->when($this->role, fn ($q) => $q->where('role', $this->role))
                ->orderByRaw("CASE WHEN role = 'manager' THEN 0 ELSE 1 END")->orderBy('name')->get(),
            'passwordUser' => $this->passwordUserId ? User::find($this->passwordUserId) : null,
        ]);
    }
}
