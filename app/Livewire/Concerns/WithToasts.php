<?php

namespace App\Livewire\Concerns;

use App\Exceptions\BusinessRuleException;

/**
 * Consistent feedback for every Livewire screen.
 *  - toast():   non-blocking success/info/error message (top-right)
 *  - attempt(): runs a service call; business-rule failures become a readable error toast
 *               instead of an error page, so the user always knows what happened and what to do.
 */
trait WithToasts
{
    use ClearsErrorsOnInput;

    protected function toast(string $message, string $type = 'success', ?string $title = null): void
    {
        $this->dispatch('toast', type: $type, message: $message, title: $title);
    }

    protected function flashToast(string $message, string $type = 'success'): void
    {
        session()->flash('toast', ['type' => $type, 'message' => $message]);
    }

    protected function attempt(callable $callback, ?string $success = null): mixed
    {
        try {
            $result = $callback();
            if ($success) {
                $this->toast($success);
            }

            return $result ?? true;
        } catch (BusinessRuleException $e) {
            $this->toast($e->getMessage(), 'error');

            return false;
        }
    }
}
