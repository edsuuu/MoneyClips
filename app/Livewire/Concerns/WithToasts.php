<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

trait WithToasts
{
    public function toast(string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', message: $message, variant: $variant);
    }
}
