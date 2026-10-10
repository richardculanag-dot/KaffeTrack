<?php

namespace App\Livewire;

use App\Livewire\Concerns\WithToasts;
use App\Rules\DisplayName;
use App\Rules\UniqueName;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('My profile')]
class Profile extends Component
{
    use WithToasts;

    public string $name = '';
    public string $email = '';
    public string $phone = '';

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public string $pin = '';
    public string $pin_password = '';

    public function mount(): void
    {
        $u = auth()->user();
        $this->name = $u->name;
        $this->email = $u->email;
        $this->phone = $u->phone ?? '';
    }

    public function saveProfile(): void
    {
        $this->email = mb_strtolower(trim($this->email));
        $data = $this->validate([
            'name' => ['required', 'string', 'max:150', new DisplayName('“Bea Ramirez”')],
            'email' => ['required', 'email', 'max:255', new UniqueName('users', 'email', auth()->id())],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
        ]);
        auth()->user()->update($data + ['phone' => $this->phone ?: null]);
        $this->toast('Profile updated.');
    }

    public function changePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], ['current_password.current_password' => 'Your current password is incorrect.', 'password.different' => 'Choose a password you haven\'t used here.'],
            ['password' => 'new password']);

        auth()->user()->update(['password' => $this->password]);
        AuditLogger::log('users', 'password_changed', auth()->user()->name.' changed their password', auth()->user());
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->toast('Password changed.');
    }

    public function changePin(): void
    {
        abort_unless(auth()->user()->isManager(), 403);
        $this->validate([
            'pin' => ['required', 'digits_between:4,6'],
            'pin_password' => ['required', 'current_password'],
        ], ['pin_password.current_password' => 'Your password is incorrect.'], ['pin' => 'PIN', 'pin_password' => 'password']);

        if (Hash::check($this->pin, (string) auth()->user()->pin)) {
            $this->addError('pin', 'This is already your PIN.');

            return;
        }
        auth()->user()->update(['pin' => $this->pin]);
        AuditLogger::log('users', 'pin_changed', auth()->user()->name.' changed their refund PIN', auth()->user());
        $this->reset('pin', 'pin_password');
        $this->toast('Refund PIN updated.');
    }

    public function render()
    {
        return view('livewire.profile');
    }
}
