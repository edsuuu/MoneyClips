<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * Dispara toasts para o container global (resources/views/components/ui/toasts.blade.php).
 * Substitui o Flux::toast() — o evento Livewire vira um CustomEvent `toast` no browser.
 */
trait WithToasts
{
    public function toast(string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', message: $message, variant: $variant);
    }
}
