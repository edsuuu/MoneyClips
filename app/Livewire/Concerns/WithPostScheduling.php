<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\PostProviderEnum;
use App\Enums\PostStatusEnum;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\Posting\PostSchedulerService;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use RuntimeException;

/**
 * Modal "Agendar postagem" + ações por post (cancelar, tentar de novo) e a
 * linha de estado por plataforma, iguais no card de /meus-videos e na /agenda.
 */
trait WithPostScheduling
{
    private const array POST_STATUS_COLORS = [
        'scheduled' => 'sky',
        'posting' => 'amber',
        'published' => 'green',
        'failed' => 'red',
        'missed' => 'amber',
        'canceled' => 'zinc',
    ];

    private const array WEEKDAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    public ?int $schedulingShortId = null;

    /** @var list<int|string> */
    public array $scheduleAccountIds = [];

    public string $scheduleWhen = 'next';

    public string $scheduleAt = '';

    public function openSchedule(int $shortId): void
    {
        $short = YoutubeShort::query()->find($shortId);
        if (! $short instanceof YoutubeShort) {
            return;
        }

        $this->authorize('update', $short);
        $this->resetValidation();

        $this->schedulingShortId = $short->id;
        $this->scheduleWhen = 'next';
        $this->scheduleAt = '';
        $this->scheduleAccountIds = array_values($this->schedulableAccounts($short)->map(fn (SocialAccount $account): int => $account->id)->all());
    }

    public function closeSchedule(): void
    {
        $this->reset(['schedulingShortId', 'scheduleAccountIds', 'scheduleWhen', 'scheduleAt']);
        $this->resetValidation();
    }

    public function saveSchedule(): void
    {
        $short = $this->schedulingShortId !== null ? YoutubeShort::query()->find($this->schedulingShortId) : null;
        if (! $short instanceof YoutubeShort) {
            $this->closeSchedule();

            return;
        }

        $this->authorize('update', $short);
        $this->resetValidation();

        $chosen = array_map(intval(...), $this->scheduleAccountIds);
        $accounts = $this->schedulableAccounts($short)->filter(fn (SocialAccount $account): bool => in_array($account->id, $chosen, true));
        if ($accounts->isEmpty()) {
            $this->addError('scheduleAccountIds', $chosen === [] ? 'Escolha pelo menos uma conta.' : 'Esse Short já está agendado nessa conta.');

            return;
        }

        $at = null;
        if ($this->scheduleWhen === 'custom') {
            $at = $this->parsedScheduleAt();
            if (! $at instanceof CarbonImmutable) {
                $this->addError('scheduleAt', 'Escolha uma data e hora no futuro.');

                return;
            }
        }

        $scheduler = resolve(PostSchedulerService::class);
        $done = [];

        try {
            foreach ($accounts as $account) {
                $post = $scheduler->schedule($short, $account, $at);
                if ($post instanceof SocialPost) {
                    $done[] = ['platform' => $this->platformLabel($account->platform), 'when' => $this->whenLabel($post->scheduled_for)];
                }
            }
        } catch (RuntimeException $runtimeException) {
            report($runtimeException);
            $this->toast(sprintf('Não deu para agendar: %s', $runtimeException->getMessage()), 'danger');

            return;
        }

        if ($done === []) {
            $this->addError('scheduleAccountIds', 'Esse Short já está agendado nessa conta.');

            return;
        }

        $this->closeSchedule();
        $this->toast($this->scheduledToast($done));
    }

    public function cancelPost(int $postId): void
    {
        $post = SocialPost::query()->find($postId);
        if (! $post instanceof SocialPost) {
            return;
        }

        $this->authorize('update', $post);

        $canceled = SocialPost::query()->whereKey($post->id)->where('status', PostStatusEnum::Scheduled)->update(['status' => PostStatusEnum::Canceled]);

        if ($canceled !== 1) {
            $this->toast('Não deu para cancelar: a postagem já começou.', 'danger');

            return;
        }

        $this->toast('Postagem cancelada.');
    }

    public function retryPost(int $postId): void
    {
        $post = SocialPost::query()->with('socialAccount')->find($postId);
        if (! $post instanceof SocialPost) {
            return;
        }

        $this->authorize('update', $post);

        try {
            $at = resolve(PostSchedulerService::class)->reschedule($post);
        } catch (RuntimeException $runtimeException) {
            report($runtimeException);
            $this->toast(sprintf('Não deu para agendar: %s', $runtimeException->getMessage()), 'danger');

            return;
        }

        if (! $at instanceof CarbonImmutable) {
            $this->toast('Não deu para reagendar: a postagem mudou de estado.', 'danger');

            return;
        }

        $this->toast(sprintf('Reagendado para %s.', $this->whenLabel($at)));
    }

    /**
     * Contas que entram no modal: as ativas do dono do Short (Short de canal,
     * sem dono, usa as contas de quem está agendando — só o admin vê esses).
     *
     * @return Collection<int, SocialAccount>
     */
    private function ownerAccounts(YoutubeShort $short): Collection
    {
        return SocialAccount::query()
            ->where('user_id', $short->user_id ?? $this->currentUser()->id)
            ->where('is_active', true)
            ->orderBy('platform')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, SocialAccount> */
    private function schedulableAccounts(YoutubeShort $short): Collection
    {
        $accounts = $this->ownerAccounts($short);
        $posts = $this->activePostsByAccount($short, $accounts);

        return $accounts->filter(fn (SocialAccount $account): bool => is_null($this->accountBlockedReason($account, $posts->get($account->id))))->values();
    }

    /**
     * Ids dos Shorts que ainda têm conta ativa do dono sem post ativo (ex.:
     * uma plataforma cancelada): o card e a linha da agenda oferecem Agendar.
     *
     * @param  SupportCollection<int, YoutubeShort>  $shorts
     * @return list<int>
     */
    private function schedulableShortIds(SupportCollection $shorts): array
    {
        $shorts = $shorts->unique('id');
        $ownerOf = fn (YoutubeShort $short): int => $short->user_id ?? $this->currentUser()->id;

        $accounts = SocialAccount::query()
            ->whereIn('user_id', $shorts->map($ownerOf)->unique()->values())
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('session_status')->orWhere('session_status', '!=', SocialAccount::SESSION_INVALID))
            ->get(['id', 'user_id']);

        $taken = SocialPost::query()
            ->whereIn('youtube_short_id', $shorts->pluck('id'))
            ->where('status', '!=', PostStatusEnum::Canceled)
            ->get(['youtube_short_id', 'social_account_id']);

        $schedulable = $shorts
            ->filter(fn (YoutubeShort $short): bool => $accounts
                ->where('user_id', $ownerOf($short))
                ->whereNotIn('id', $taken->where('youtube_short_id', $short->id)->pluck('social_account_id'))
                ->isNotEmpty())
            ->map(fn (YoutubeShort $short): int => $short->id);

        return array_values($schedulable->all());
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @return Collection<int, SocialPost>
     */
    private function activePostsByAccount(YoutubeShort $short, Collection $accounts): Collection
    {
        return SocialPost::query()
            ->where('youtube_short_id', $short->id)
            ->whereIn('social_account_id', $accounts->modelKeys())
            ->where('status', '!=', PostStatusEnum::Canceled)
            ->get()
            ->keyBy('social_account_id');
    }

    private function accountBlockedReason(SocialAccount $account, ?SocialPost $post): ?string
    {
        return match (true) {
            $post?->status === PostStatusEnum::Published => 'Já postado',
            in_array($post?->status, [PostStatusEnum::Scheduled, PostStatusEnum::Posting], true) => 'Já agendado · '.self::WEEKDAYS[$post->scheduled_for->dayOfWeek].' '.$post->scheduled_for->format('H:i'),
            $post instanceof SocialPost => 'Precisa de você · resolva na Agenda',
            $account->session_status === SocialAccount::SESSION_INVALID => 'Desconectada · reconecte em Contas',
            default => null,
        };
    }

    private function parsedScheduleAt(): ?CarbonImmutable
    {
        try {
            $at = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->scheduleAt);
        } catch (InvalidFormatException) {
            return null;
        }

        return $at instanceof CarbonImmutable && $at->isFuture() ? $at->startOfMinute() : null;
    }

    /**
     * @return array{title: string, accounts: list<array{id: int, label: string, provider_label: string, blocked_reason: string|null, is_blocked: bool}>, can_schedule: bool, next_label: string, times_help: string, min_at: string}|null
     */
    private function scheduleModal(): ?array
    {
        $short = $this->schedulingShortId !== null ? YoutubeShort::query()->forUser($this->currentUser())->find($this->schedulingShortId) : null;
        if (! $short instanceof YoutubeShort) {
            return null;
        }

        $accounts = $this->ownerAccounts($short);
        $posts = $this->activePostsByAccount($short, $accounts);
        $scheduler = resolve(PostSchedulerService::class);
        $rows = [];
        $nextSlots = [];

        foreach ($accounts as $account) {
            $blocked = $this->accountBlockedReason($account, $posts->get($account->id));
            if (is_null($blocked)) {
                try {
                    $nextSlots[] = ['platform' => $this->platformLabel($account->platform), 'when' => $this->whenLabel($scheduler->nextSlot($account))];
                } catch (RuntimeException) {
                    $blocked = 'Sem horário livre na grade';
                }
            }

            $rows[] = [
                'id' => $account->id,
                'label' => $this->platformLabel($account->platform).' · '.$account->name,
                'provider_label' => $this->providerLabel($account->provider),
                'blocked_reason' => $blocked,
                'is_blocked' => ! is_null($blocked),
            ];
        }

        return [
            'title' => $short->title ?? $short->youtube_id,
            'accounts' => $rows,
            'can_schedule' => $nextSlots !== [],
            'next_label' => $this->slotsLabel($nextSlots),
            'times_help' => $this->goodTimesLabel().' Meio-dia fica de fora: é o pior horário do canal.',
            'min_at' => CarbonImmutable::now()->addMinutes(5)->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * @return array{id: int, short_id: int, short_title: string, platform_label: string, account_name: string, badge_label: string, badge_color: string, is_posting: bool, is_private: bool, time_label: string, detail: string, url: string|null, url_label: string, can_cancel: bool, cancel_confirm: string, action: string|null, action_label: string}
     */
    private function postRow(SocialPost $post): array
    {
        $platform = $this->platformLabel($post->socialAccount->platform);
        $title = $post->youtubeShort->title ?? $post->youtubeShort->youtube_id;
        $action = $this->postAction($post);

        return [
            'id' => $post->id,
            'short_id' => $post->youtube_short_id,
            'short_title' => $title,
            'platform_label' => $platform,
            'account_name' => $post->socialAccount->name,
            'badge_label' => $post->status->label(),
            'badge_color' => self::POST_STATUS_COLORS[$post->status->value],
            'is_posting' => $post->status === PostStatusEnum::Posting,
            'is_private' => $post->status === PostStatusEnum::Published && $post->privacy === 'private',
            'time_label' => $post->scheduled_for->format('H:i'),
            'detail' => $this->postDetail($post),
            'url' => $post->status === PostStatusEnum::Published ? $post->url : null,
            'url_label' => 'Ver no '.$platform,
            'can_cancel' => $post->status === PostStatusEnum::Scheduled,
            'cancel_confirm' => sprintf('Cancelar a postagem de "%s" no %s?', $title, $platform),
            'action' => $action,
            'action_label' => match ($action) {
                'reconnect' => 'Reconectar conta',
                'retry' => $post->status === PostStatusEnum::Missed ? 'Reagendar' : 'Tentar de novo',
                default => '',
            },
        ];
    }

    private function postDetail(SocialPost $post): string
    {
        return match ($post->status) {
            PostStatusEnum::Scheduled => $this->whenLabel($post->scheduled_for),
            PostStatusEnum::Posting => 'Enviando agora',
            PostStatusEnum::Published => $this->whenLabel($post->posted_at ?? $post->scheduled_for),
            PostStatusEnum::Failed => $post->error ?? 'Falhou sem motivo registrado.',
            PostStatusEnum::Missed => sprintf('Não saiu às %s: o MoneyClips estava fora do ar.', $post->scheduled_for->format('H:i')),
            PostStatusEnum::Canceled => $post->status->label(),
        };
    }

    /**
     * Failed pede o botão certo: problema na conta (desativada, sessão
     * inválida, token sem refresh) leva a /contas; o resto tenta de novo.
     */
    private function postAction(SocialPost $post): ?string
    {
        if ($post->status === PostStatusEnum::Missed) {
            return 'retry';
        }

        if ($post->status !== PostStatusEnum::Failed) {
            return null;
        }

        $account = $post->socialAccount;
        $needsReconnect = ! $account->is_active
            || $account->session_status === SocialAccount::SESSION_INVALID
            || ($account->tokenExpired() && blank($account->refresh_token))
            || str_contains(mb_strtolower((string) $post->error), 'invalid_grant');

        return $needsReconnect ? 'reconnect' : 'retry';
    }

    private function whenLabel(CarbonImmutable $at): string
    {
        $time = $at->format('H:i');

        return match (true) {
            $at->isToday() => 'hoje às '.$time,
            $at->isTomorrow() => 'amanhã às '.$time,
            $at->isYesterday() => 'ontem às '.$time,
            default => $this->dayName($at).' às '.$time,
        };
    }

    /** @param  list<array{platform: string, when: string}>  $slots */
    private function slotsLabel(array $slots): string
    {
        $whens = array_values(array_unique(array_column($slots, 'when')));
        if (count($whens) <= 1) {
            return $whens[0] ?? '';
        }

        return implode(' · ', array_map(fn (array $slot): string => $slot['platform'].' '.$slot['when'], $slots));
    }

    /** @param  non-empty-list<array{platform: string, when: string}>  $done */
    private function scheduledToast(array $done): string
    {
        $whens = array_values(array_unique(array_column($done, 'when')));
        if (count($whens) > 1) {
            return sprintf('Agendado: %s.', $this->slotsLabel($done));
        }

        $platforms = array_map(fn (string $platform): string => 'no '.$platform, array_values(array_unique(array_column($done, 'platform'))));

        return sprintf('Agendado: %s %s.', $whens[0], Arr::join($platforms, ', ', ' e '));
    }

    private function goodTimesLabel(): string
    {
        /** @var list<string> $times */
        $times = config('posting.times');
        $hours = array_map(fn (string $time): CarbonImmutable => CarbonImmutable::today()->setTimeFromTimeString($time), $times);
        sort($hours);

        $labels = array_map(fn (CarbonImmutable $hour): string => $hour->format('G').'h'.($hour->minute === 0 ? '' : $hour->format('i')), $hours);

        return sprintf('Horários bons: %s.', Arr::join($labels, ', ', ' e '));
    }

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'tiktok' => 'TikTok',
            'youtube' => 'YouTube',
            default => ucfirst($platform),
        };
    }

    private function providerLabel(PostProviderEnum $provider): string
    {
        return match ($provider) {
            PostProviderEnum::YoutubeApi => 'API oficial',
            PostProviderEnum::TiktokUploader => 'pelo navegador',
        };
    }

    private function dayName(CarbonImmutable $date): string
    {
        return self::WEEKDAYS[$date->dayOfWeek].', '.$date->format('d/m');
    }
}
