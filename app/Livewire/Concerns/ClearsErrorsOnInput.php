<?php

namespace App\Livewire\Concerns;

/**
 * As soon as the user changes a field that has an error, the error message disappears.
 * The field is validated again on submit. This avoids "stale" errors that still complain
 * after the user has already fixed the problem.
 */
trait ClearsErrorsOnInput
{
    public function updatedClearsErrorsOnInput(string $name): void
    {
        if ($this->getErrorBag()->has($name)) {
            $this->resetValidation($name);
        }
    }

    /** Livewire calls updated() for every property change. */
    public function updated(string $name): void
    {
        $this->updatedClearsErrorsOnInput($name);
    }
}
