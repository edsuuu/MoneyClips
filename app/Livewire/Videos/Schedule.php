<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Models\Cut;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\Video;
use App\Services\SocialPublishing\SocialPublisherRegistry;
use App\Support\Cast;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use Livewire\Component;

final class Schedule extends Component
{
    public Video $video;

    /** @var array<int, string> Cut uuids selecionados (a ordem de postagem segue o index do corte). */
    public array $selectedCuts = [];

    /** @var array<string, array{title: string, description: string, hashtags: string}> Metadados por corte (uuid). */
    public array $cutMeta = [];

    /** @var array<string, bool> Plataformas marcadas. */
    public array $platforms = [];

    /** @var array<string, string> Conta (uuid) escolhida por plataforma. */
    public array $account = [];

    /** @var array<string, string> Início da publicação por plataforma (datetime-local). */
    public array $startAt = [];

    /** @var array<string, int> Intervalo em minutos entre um corte e o próximo, por plataforma. */
    public array $intervalMinutes = [];

    public function mount(Video $video, SocialPublisherRegistry $registry): void
    {
        $this->video = $video;
        $this->video->load('cuts');

        $defaultStart = Date::now()->addHour()->format('Y-m-d\TH:i');

        foreach (array_keys($registry->all()) as $key) {
            $this->platforms[$key] = false;
            $this->account[$key] = '';
            $this->startAt[$key] = $defaultStart;
            $this->intervalMinutes[$key] = 30;
        }

        foreach ($this->video->cuts as $cut) {
            $this->cutMeta[$cut->uuid] = [
                'title' => (string) ($cut->title ?? ''),
                'description' => (string) ($cut->description ?? ''),
                'hashtags' => $this->stringifyHashtags($cut->hashtags),
            ];
        }
    }

    public function schedule(SocialPublisherRegistry $registry): void
    {
        $platforms = array_keys(array_filter($this->platforms, static fn (bool $on): bool => $on));

        if ($platforms === []) {
            Flux::toast('Selecione ao menos uma plataforma.', variant: 'danger');

            return;
        }

        if ($this->selectedCuts === []) {
            Flux::toast('Selecione ao menos um corte.', variant: 'danger');

            return;
        }

        // Cortes na ordem de publicação (segue o index do corte).
        $orderedCuts = $this->video->cuts()
            ->whereIn('uuid', $this->selectedCuts)
            ->orderBy('index')
            ->get();

        if ($orderedCuts->isEmpty()) {
            Flux::toast('Nenhum corte válido selecionado.', variant: 'danger');

            return;
        }

        // Validação por plataforma: precisa de conta conectada e horário.
        $resolvedAccounts = [];
        foreach ($platforms as $platform) {
            if (($this->startAt[$platform] ?? '') === '') {
                Flux::toast(sprintf('Defina o horário de início para %s.', $platform), variant: 'danger');

                return;
            }

            $accountUuid = $this->account[$platform] ?? '';
            $account = $accountUuid !== ''
                ? SocialAccount::query()->where('uuid', $accountUuid)->where('platform', $platform)->where('is_active', true)->first()
                : null;

            if (! $account instanceof SocialAccount) {
                $label = $registry->for($platform)?->label() ?? $platform;
                Flux::toast(sprintf('Conecte uma conta de %s antes de agendar.', $label), variant: 'danger');

                return;
            }

            $resolvedAccounts[$platform] = $account;
        }

        $created = 0;

        foreach ($platforms as $platform) {
            $account = $resolvedAccounts[$platform];
            $start = Date::parse($this->startAt[$platform]);
            $interval = max(0, (int) ($this->intervalMinutes[$platform] ?? 0));

            $sequence = 0;
            foreach ($orderedCuts as $cut) {
                $sequence++;
                $meta = $this->metaFor($cut);

                $when = $start->copy()->addMinutes($interval * ($sequence - 1));

                $post = ScheduledPost::query()->create([
                    'video_id' => $this->video->id,
                    'cut_id' => $cut->id,
                    'social_account_id' => $account->id,
                    'platform' => $platform,
                    'sequence' => $sequence,
                    'title' => $meta['title'] !== '' ? $meta['title'] : ($cut->name ?? null),
                    'description' => $meta['description'],
                    'hashtags' => $meta['hashtags'],
                    'scheduled_for' => $when,
                    'status' => ScheduledPost::STATUS_SCHEDULED,
                    'created_by' => Auth::id(),
                ]);

                $post->log('info', sprintf('Agendado para %s em %s.', $when->format('d/m/Y H:i'), Cast::str($account->name)));
                $created++;
            }
        }

        Flux::toast($created.' publicação(ões) agendada(s) com sucesso.');
        $this->reset('selectedCuts');
    }

    public function render(SocialPublisherRegistry $registry): View
    {
        $this->video->refresh()->load('cuts.files');

        $accounts = SocialAccount::query()
            ->where('is_active', true)
            ->orderBy('platform')
            ->get()
            ->groupBy('platform');

        $recentPosts = ScheduledPost::query()
            ->where('video_id', $this->video->id)
            ->with('account')
            ->latest()
            ->limit(50)
            ->get();

        return view('livewire.videos.schedule', [
            'cuts' => $this->video->cuts,
            'platformLabels' => $registry->labels(),
            'accountsByPlatform' => $accounts,
            'recentPosts' => $recentPosts,
        ]);
    }

    /**
     * @return array{title: string, description: string, hashtags: list<string>}
     */
    private function metaFor(Cut $cut): array
    {
        $meta = $this->cutMeta[$cut->uuid] ?? [];

        return [
            'title' => mb_trim((string) ($meta['title'] ?? '')),
            'description' => mb_trim((string) ($meta['description'] ?? '')),
            'hashtags' => $this->parseHashtags((string) ($meta['hashtags'] ?? '')),
        ];
    }

    /**
     * @param  mixed  $hashtags
     */
    private function stringifyHashtags($hashtags): string
    {
        if (! is_array($hashtags)) {
            return '';
        }

        return implode(' ', array_map(static fn ($t): string => '#'.mb_ltrim(Cast::str($t), '#'), $hashtags));
    }

    /**
     * @return list<string>
     */
    private function parseHashtags(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $t): string => mb_ltrim(mb_trim($t), '#'),
            $parts,
        ), static fn (string $t): bool => $t !== ''));
    }
}
