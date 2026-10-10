<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Sign in')]
class Login extends Component
{
    #[Validate('required|string|max:150', as: 'username or email')]
    public string $login = '';

    #[Validate('required|string', as: 'password')]
    public string $password = '';

    public bool $remember = false;

    public function authenticate()
    {
        $this->validate();

        // Brute-force protection: 5 attempts per minute per login + IP. The key uses the same trimmed,
        // lower-case value as the lookup, so " admin" and "admin " cannot each get their own 5 tries.
        $value = mb_strtolower(trim($this->login));
        $key = 'login:'.Str::transliterate($value).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            $this->addError('login', "Too many sign-in attempts. Try again in {$seconds} seconds.");

            return null;
        }

        $field = filter_var($value, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        // Only reveal that an account is deactivated to someone who knows its password.
        $user = User::where($field, $value)->first();
        if ($user && ! $user->is_active && Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key);
            $this->addError('login', 'This account is deactivated. Please contact a manager.');

            return null;
        }

        if (! Auth::attempt([$field => $value, 'password' => $this->password, 'is_active' => true], $this->remember)) {
            RateLimiter::hit($key);
            $this->reset('password');
            $this->addError('login', 'The username/email or password is incorrect.');
            $this->dispatch('login-failed');

            return null;
        }

        RateLimiter::clear($key);
        session()->regenerate();

        $home = Auth::user()->isManager() ? route('dashboard') : route('pos');
        session()->flash('toast', ['type' => 'success', 'message' => 'Welcome back, '.Str::before(Auth::user()->name, ' ').'!']);

        return $this->redirectIntended($home, navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
