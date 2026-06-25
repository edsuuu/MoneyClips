<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Livewire\Concerns\WithToasts;
use App\Models\AutoPostSettings;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Tela /settings/auto-post: switches pra ligar/desligar postagem em cada
 * plataforma sem precisar editar .env + config:cache no servidor. As mudanças
 * impactam o próximo run do AutoPostDispatcher imediatamente.
 */
final class AutoPost extends Component
{
    use WithToasts;

    public bool $youtubeEnabled = false;

    public bool $tiktokEnabled = false;

    public function mount(): void
    {
        $settings = AutoPostSettings::current();
        $this->youtubeEnabled = $settings->youtube_enabled;
        $this->tiktokEnabled = $settings->tiktok_enabled;
    }

    public function toggleYoutube(): void
    {
        $this->youtubeEnabled = ! $this->youtubeEnabled;
        $this->persist('YouTube', $this->youtubeEnabled);
    }

    public function toggleTiktok(): void
    {
        $this->tiktokEnabled = ! $this->tiktokEnabled;
        $this->persist('TikTok', $this->tiktokEnabled);
    }

    public function render(): View
    {
        $settings = AutoPostSettings::current();
        $settings->load('updatedBy');

        return view('livewire.settings.auto-post', [
            'settings' => $settings,
        ]);
    }

    private function persist(string $label, bool $value): void
    {
        $settings = AutoPostSettings::current();
        $settings->youtube_enabled = $this->youtubeEnabled;
        $settings->tiktok_enabled = $this->tiktokEnabled;

        $userId = auth()->id();
        $settings->updated_by_user_id = is_numeric($userId) ? max(0, (int) $userId) : null;
        $settings->save();

        $this->toast(sprintf('%s %s.', $label, $value ? 'ativado' : 'pausado'));
    }
}
