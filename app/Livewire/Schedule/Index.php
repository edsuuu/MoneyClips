<?php

declare(strict_types=1);

namespace App\Livewire\Schedule;

use App\Enums\PostStatusEnum;
use App\Livewire\Concerns\WithCurrentUser;
use App\Livewire\Concerns\WithPostScheduling;
use App\Livewire\Concerns\WithToasts;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\Posting\PostSchedulerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\View\View;
use Livewire\Component;
use RuntimeException;

final class Index extends Component
{
    use WithCurrentUser;
    use WithPostScheduling;
    use WithToasts;

    private const int DAYS = 7;

    private const array ATTENTION_STATUSES = [PostStatusEnum::Failed, PostStatusEnum::Missed];

    public function retryAllMissed(): void
    {
        try {
            $count = resolve(PostSchedulerService::class)->rescheduleMissed($this->currentUser());
        } catch (RuntimeException $runtimeException) {
            report($runtimeException);
            $this->toast(sprintf('Não deu para agendar: %s', $runtimeException->getMessage()), 'danger');

            return;
        }

        $this->toast(match ($count) {
            0 => 'Nenhuma postagem perdida para reagendar.',
            1 => '1 postagem reagendada.',
            default => sprintf('%d postagens reagendadas.', $count),
        });
    }

    /**
     * @param  Collection<int, SocialPost>  $posts
     * @param  list<int>  $schedulableShortIds
     * @return list<array{key: string, label: string, count_label: string, rows: list<array{key: string, time: string, short_id: int, title: string, can_schedule: bool, posts: list<array<string, mixed>>}>}>
     */
    private function days(Collection $posts, array $schedulableShortIds): array
    {
        $days = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $date = CarbonImmutable::today()->addDays($offset);
            $dayPosts = $posts->filter(fn (SocialPost $post): bool => $post->scheduled_for->isSameDay($date));

            $rows = [];
            foreach ($dayPosts->groupBy(fn (SocialPost $post): string => $post->scheduled_for->format('Hi').'-'.$post->youtube_short_id) as $key => $group) {
                $first = $group->firstOrFail();
                $rows[] = [
                    'key' => (string) $key,
                    'time' => $first->scheduled_for->format('H:i'),
                    'short_id' => $first->youtube_short_id,
                    'title' => $first->youtubeShort->title ?? $first->youtubeShort->youtube_id,
                    'can_schedule' => in_array($first->youtube_short_id, $schedulableShortIds, true),
                    'posts' => array_values($group->map($this->postRow(...))->all()),
                ];
            }

            $shorts = $dayPosts->pluck('youtube_short_id')->unique()->count();

            $days[] = [
                'key' => $date->format('Y-m-d'),
                'label' => $this->dayLabel($date),
                'count_label' => match ($shorts) {
                    0 => '',
                    1 => '1 Short',
                    default => sprintf('%d Shorts', $shorts),
                },
                'rows' => $rows,
            ];
        }

        return $days;
    }

    private function dayLabel(CarbonImmutable $date): string
    {
        $day = $this->dayName($date);

        return match (true) {
            $date->isToday() => 'Hoje · '.$day,
            $date->isTomorrow() => 'Amanhã · '.$day,
            default => $day,
        };
    }

    /**
     * Dias corridos (hoje conta) até o último agendado, pela conta ativa
     * mais curta: conta ativa sem nada agendado zera a cobertura. Conta
     * desconectada fica de fora: ela já aparece em "precisa de você".
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  Collection<int, SocialPost>  $scheduled
     */
    private function coveredDays(Collection $accounts, Collection $scheduled): int
    {
        $perAccount = $accounts->reject(fn (SocialAccount $account): bool => $account->session_status === SocialAccount::SESSION_INVALID)->map(function (SocialAccount $account) use ($scheduled): int {
            $last = $scheduled->where('social_account_id', $account->id)->sortByDesc('scheduled_for')->first();

            return $last instanceof SocialPost ? (int) CarbonImmutable::today()->diffInDays($last->scheduled_for->startOfDay()) + 1 : 0;
        });

        return (int) ($perAccount->min() ?? 0);
    }

    private function summaryLabel(int $scheduled, int $covered): string
    {
        if ($scheduled === 0) {
            return 'Nada agendado';
        }

        $count = $scheduled === 1 ? '1 agendado' : sprintf('%d agendados', $scheduled);
        if ($covered === 0) {
            return $count;
        }

        return sprintf('%s · %s %d %s', $count, $scheduled === 1 ? 'cobre' : 'cobrem', $covered, $covered === 1 ? 'dia' : 'dias');
    }

    private function shortAgendaWarning(int $scheduled, int $covered, bool $hasAuto): ?string
    {
        if ($scheduled === 0 || $covered > 2 || $hasAuto) {
            return null;
        }

        return sprintf('A agenda acaba %s. Marque mais Shorts como prontos ou ligue o modo Automático.', $covered === 2 ? 'amanhã' : 'hoje');
    }

    private function readyShortsCount(): int
    {
        return YoutubeShort::query()->forUser($this->currentUser())
            ->whereNotNull('video_path')
            ->whereNotNull('ready_at')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at')
            ->count();
    }

    public function render(): View
    {
        $user = $this->currentUser();
        $accounts = SocialAccount::query()->forUser($user)->get();
        $activeAccounts = $accounts->where('is_active', true)->values();

        $posts = SocialPost::query()
            ->forUser($user)
            ->with(['youtubeShort', 'socialAccount'])
            ->where('status', '!=', PostStatusEnum::Canceled)
            ->where('scheduled_for', '>=', CarbonImmutable::today()->subDays(PostSchedulerService::ATTENTION_DAYS))
            ->where('scheduled_for', '<', CarbonImmutable::today()->addDays(self::DAYS))
            ->oldest('scheduled_for')
            ->get();

        $attention = $posts->filter(fn (SocialPost $post): bool => in_array($post->status, self::ATTENTION_STATUSES, true))->values();
        $listed = $posts->filter(fn (SocialPost $post): bool => ! in_array($post->status, self::ATTENTION_STATUSES, true) && ! $post->scheduled_for->isBefore(CarbonImmutable::today()))->values();

        $scheduled = SocialPost::query()->forUser($user)->where('status', PostStatusEnum::Scheduled)->get(['id', 'social_account_id', 'scheduled_for']);
        $covered = $this->coveredDays($activeAccounts, $scheduled);
        $next = $scheduled->filter(fn (SocialPost $post): bool => $post->scheduled_for->isFuture())->sortBy('scheduled_for')->first();
        $attentionCount = $attention->count();
        $missedCount = $attention->where('status', PostStatusEnum::Missed)->count();
        $isEmpty = $listed->isEmpty() && $scheduled->isEmpty();

        /** @var SupportCollection<int, array<string, mixed>> $attentionRows */
        $attentionRows = $attention->map($this->postRow(...));

        return view('livewire.schedule.index', [
            'subtitle' => sprintf('Seus Shorts saem sozinhos nos horários marcados. %s', $this->goodTimesLabel()),
            'hasAccounts' => $accounts->isNotEmpty(),
            'summaryLabel' => $this->summaryLabel($scheduled->count(), $covered),
            'nextLabel' => $next instanceof SocialPost ? 'Próxima postagem: '.$this->whenLabel($next->scheduled_for) : null,
            'shortAgendaWarning' => $this->shortAgendaWarning($scheduled->count(), $covered, $activeAccounts->contains('auto_schedule', true)),
            'attention' => $attentionRows->all(),
            'attentionTitle' => $attentionCount === 1 ? '1 postagem precisa de você' : sprintf('%d postagens precisam de você', $attentionCount),
            'missedLabel' => $missedCount > 0 ? sprintf('Reagendar perdidas (%d)', $missedCount) : null,
            'emptyText' => match (true) {
                ! $isEmpty => null,
                $this->readyShortsCount() === 0 => 'Não há Shorts prontos. Revise os Shorts em Meus vídeos.',
                default => 'Marque Shorts como prontos em Meus vídeos e clique em Agendar. Ou ligue o modo Automático em Contas e a agenda se preenche sozinha.',
            },
            'days' => $isEmpty ? [] : $this->days($listed, $this->schedulableShortIds($listed->map(fn (SocialPost $post): YoutubeShort => $post->youtubeShort))),
            'hasPosting' => $posts->contains('status', PostStatusEnum::Posting),
            'scheduleModal' => $this->scheduleModal(),
        ]);
    }
}
