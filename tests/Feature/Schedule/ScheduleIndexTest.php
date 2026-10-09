<?php

declare(strict_types=1);

use App\Enums\PostStatusEnum;
use App\Enums\RoleEnum;
use App\Livewire\Schedule\Index;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\YoutubeShort;
use Database\Seeders\Seeder001Roles;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

function agendaAccount(User $user, string $platform, array $attributes = []): SocialAccount
{
    return SocialAccount::query()->create([
        'user_id' => $user->id,
        'platform' => $platform,
        'name' => $platform === 'tiktok' ? '@'.$user->id.'_clips' : 'Canal '.$user->id,
        'is_active' => true,
        ...$attributes,
    ]);
}

function agendaPost(YoutubeShort $short, SocialAccount $account, string $at, PostStatusEnum $status = PostStatusEnum::Scheduled, array $attributes = []): SocialPost
{
    return SocialPost::query()->create([
        'youtube_short_id' => $short->id,
        'social_account_id' => $account->id,
        'scheduled_for' => $at,
        'status' => $status,
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    Date::setTestNow('2026-10-08 10:30:00');
    config([
        'posting.times' => ['08:00', '10:00', '14:00', '17:00', '20:00', '21:30'],
        'posting.per_day' => 6,
        'posting.min_gap_minutes' => 60,
    ]);

    $this->seed(Seeder001Roles::class);
    $this->creator = User::factory()->create()->assignRole(RoleEnum::Creator);
    $this->other = User::factory()->create()->assignRole(RoleEnum::Creator);
    $this->admin = User::factory()->create()->assignRole(RoleEnum::Admin);

    $this->tiktok = agendaAccount($this->creator, 'tiktok');
    $this->youtube = agendaAccount($this->creator, 'youtube');
    $this->short = YoutubeShort::factory()->for($this->creator)->ready()->create(['title' => 'O lado podre do crime']);

    $this->actingAs($this->creator);
});

it('groups the next 7 days by day and by Short + time, one line per platform', function (): void {
    agendaPost($this->short, $this->tiktok, '2026-10-08 20:00');
    agendaPost($this->short, $this->youtube, '2026-10-08 20:00');
    $later = YoutubeShort::factory()->for($this->creator)->ready()->create(['title' => 'This is the secret']);
    agendaPost($later, $this->tiktok, '2026-10-09 09:00');
    agendaPost($later, $this->youtube, '2026-10-20 09:00');

    Livewire::test(Index::class)
        ->assertSee('Hoje · qui, 08/10')
        ->assertSee('Amanhã · sex, 09/10')
        ->assertSee('Nada agendado.')
        ->assertSee('O lado podre do crime')
        ->assertSee('This is the secret')
        ->assertSee('4 agendados · cobrem 2 dias')
        ->assertSee('Próxima postagem: hoje às 20:00')
        ->assertViewHas('days', function (array $days): bool {
            $today = $days[0]['rows'];

            return count($days) === 7
                && count($today) === 1
                && $today[0]['time'] === '20:00'
                && array_column($today[0]['posts'], 'platform_label') === ['TikTok', 'YouTube']
                && $days[0]['count_label'] === '1 Short'
                && $days[1]['rows'][0]['title'] === 'This is the secret';
        });
});

it('shows each state with its own label and action', function (): void {
    $published = YoutubeShort::factory()->for($this->creator)->ready()->create(['title' => 'A partícula de Deus']);
    agendaPost($published, $this->tiktok, '2026-10-08 08:00', PostStatusEnum::Published, ['url' => 'https://www.tiktok.com/@x/video/1', 'privacy' => 'private', 'posted_at' => '2026-10-08 08:02']);
    agendaPost($published, $this->youtube, '2026-10-08 10:00', PostStatusEnum::Posting, ['started_at' => '2026-10-08 10:00']);
    agendaPost($this->short, $this->tiktok, '2026-10-07 20:00', PostStatusEnum::Missed);
    agendaPost($this->short, $this->youtube, '2026-10-07 21:30', PostStatusEnum::Failed, ['error' => 'Quota do YouTube estourada.']);

    Livewire::test(Index::class)
        ->assertSee('Postado')
        ->assertSee('Saiu privado')
        ->assertSee('Ver no TikTok')
        ->assertSee('Postando…')
        ->assertSee('Enviando agora')
        ->assertSee('2 postagens precisam de você')
        ->assertSee('Perdeu o horário')
        ->assertSee('Não saiu às 20:00: o MoneyClips estava fora do ar.')
        ->assertSee('Reagendar perdidas (1)')
        ->assertSee('Falhou')
        ->assertSee('Quota do YouTube estourada.')
        ->assertSee('Tentar de novo')
        ->assertSeeHtml('wire:poll.10s');
});

it('asks to reconnect when the failure comes from the account', function (): void {
    $this->tiktok->update(['session_status' => SocialAccount::SESSION_INVALID]);
    agendaPost($this->short, $this->tiktok, '2026-10-08 08:00', PostStatusEnum::Failed, ['error' => 'Sessão da conta inválida: reconecte em /contas.']);

    Livewire::test(Index::class)
        ->assertSee('Reconectar conta')
        ->assertDontSee('Tentar de novo')
        ->assertDontSee('Reagendar perdidas');
});

it('polls only while a post is going out', function (): void {
    agendaPost($this->short, $this->tiktok, '2026-10-08 20:00');

    Livewire::test(Index::class)->assertDontSeeHtml('wire:poll.10s');
});

it('scopes the agenda to the account owner and lets admin see everything', function (): void {
    agendaPost($this->short, $this->tiktok, '2026-10-08 20:00');
    $foreignShort = YoutubeShort::factory()->for($this->other)->ready()->create(['title' => 'Short alheio']);
    agendaPost($foreignShort, agendaAccount($this->other, 'tiktok'), '2026-10-08 21:30');

    Livewire::test(Index::class)->assertSee('O lado podre do crime')->assertDontSee('Short alheio');

    $this->actingAs($this->admin);
    Livewire::test(Index::class)->assertSee('O lado podre do crime')->assertSee('Short alheio');
});

it('cancels a scheduled post and refuses one that already started', function (): void {
    $scheduled = agendaPost($this->short, $this->tiktok, '2026-10-08 20:00');
    $posting = agendaPost($this->short, $this->youtube, '2026-10-08 10:00', PostStatusEnum::Posting);

    Livewire::test(Index::class)
        ->call('cancelPost', $scheduled->id)
        ->assertDispatched('toast', message: 'Postagem cancelada.', variant: 'success')
        ->call('cancelPost', $posting->id)
        ->assertDispatched('toast', message: 'Não deu para cancelar: a postagem já começou.', variant: 'danger')
        ->assertDontSee('Agendado');

    expect($scheduled->refresh()->status)->toBe(PostStatusEnum::Canceled)
        ->and($posting->refresh()->status)->toBe(PostStatusEnum::Posting);
});

it('retries a failed post on the next good time and clears the error', function (): void {
    $failed = agendaPost($this->short, $this->tiktok, '2026-10-08 08:00', PostStatusEnum::Failed, ['error' => 'Quota estourada.']);

    Livewire::test(Index::class)
        ->call('retryPost', $failed->id)
        ->assertDispatched('toast', message: 'Reagendado para hoje às 14:00.', variant: 'success');

    $failed->refresh();
    expect($failed->status)->toBe(PostStatusEnum::Scheduled)
        ->and($failed->scheduled_for->format('Y-m-d H:i'))->toBe('2026-10-08 14:00')
        ->and($failed->error)->toBeNull();
});

it('reschedules only the missed posts of the user, in their original order', function (): void {
    $older = agendaPost($this->short, $this->tiktok, '2026-10-07 08:00', PostStatusEnum::Missed);
    $newer = agendaPost(YoutubeShort::factory()->for($this->creator)->ready()->create(), $this->tiktok, '2026-10-07 20:00', PostStatusEnum::Missed);
    $failed = agendaPost($this->short, $this->youtube, '2026-10-07 20:00', PostStatusEnum::Failed, ['error' => 'Recusado.']);
    $foreign = agendaPost(YoutubeShort::factory()->for($this->other)->ready()->create(), agendaAccount($this->other, 'tiktok'), '2026-10-07 20:00', PostStatusEnum::Missed);

    Livewire::test(Index::class)
        ->call('retryAllMissed')
        ->assertDispatched('toast', message: '2 postagens reagendadas.', variant: 'success');

    expect($older->refresh()->scheduled_for->format('Y-m-d H:i'))->toBe('2026-10-08 14:00')
        ->and($newer->refresh()->scheduled_for->format('Y-m-d H:i'))->toBe('2026-10-08 17:00')
        ->and($newer->status)->toBe(PostStatusEnum::Scheduled)
        ->and($failed->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($foreign->refresh()->status)->toBe(PostStatusEnum::Missed);
});

it('answers 403 when acting on a post of another user', function (): void {
    $foreign = agendaPost(YoutubeShort::factory()->for($this->other)->ready()->create(), agendaAccount($this->other, 'tiktok'), '2026-10-08 20:00');
    $missed = agendaPost(YoutubeShort::factory()->for($this->other)->ready()->create(), $foreign->socialAccount, '2026-10-07 20:00', PostStatusEnum::Missed);

    Livewire::test(Index::class)->call('cancelPost', $foreign->id)->assertForbidden();
    Livewire::test(Index::class)->call('retryPost', $missed->id)->assertForbidden();

    expect($foreign->refresh()->status)->toBe(PostStatusEnum::Scheduled)
        ->and($missed->refresh()->status)->toBe(PostStatusEnum::Missed);
});

it('offers Agendar on the row again once a platform is canceled', function (): void {
    agendaPost($this->short, $this->tiktok, '2026-10-08 20:00');
    agendaPost($this->short, $this->youtube, '2026-10-08 20:00', PostStatusEnum::Canceled);

    Livewire::test(Index::class)
        ->assertViewHas('days', fn (array $days): bool => $days[0]['rows'][0]['can_schedule'] === true && count($days[0]['rows'][0]['posts']) === 1)
        ->call('openSchedule', $this->short->id)
        ->assertSet('scheduleAccountIds', [$this->youtube->id])
        ->call('saveSchedule')
        ->assertDispatched('toast', message: 'Agendado: hoje às 14:00 no YouTube.', variant: 'success');

    expect(SocialPost::query()->where('social_account_id', $this->youtube->id)->sole()->status)->toBe(PostStatusEnum::Scheduled);
});

it('guides the owner when there is no account or nothing scheduled', function (): void {
    Livewire::test(Index::class)
        ->assertSee('Nenhum Short agendado')
        ->assertSee('Marque Shorts como prontos em Meus vídeos e clique em Agendar.');

    $this->short->update(['ready_at' => null]);
    Livewire::test(Index::class)->assertSee('Não há Shorts prontos. Revise os Shorts em Meus vídeos.');

    $this->actingAs($this->other);
    Livewire::test(Index::class)
        ->assertSee('Conecte uma conta para postar')
        ->assertDontSee('Nenhum Short agendado');
});

it('warns when the agenda runs out today', function (): void {
    agendaPost($this->short, $this->tiktok, '2026-10-08 20:00');
    agendaPost($this->short, $this->youtube, '2026-10-08 21:30');

    Livewire::test(Index::class)
        ->assertSee('2 agendados · cobrem 1 dia')
        ->assertSee('A agenda acaba hoje.');
});

it('serves /agenda with the navbar link and every tour step anchored', function (): void {
    agendaPost($this->short, $this->tiktok, '2026-10-07 20:00', PostStatusEnum::Missed);

    $response = $this->get('/agenda')
        ->assertOk()
        ->assertSee('href="'.route('schedule.index').'"', false)
        ->assertSee('guidedTour(', false);

    foreach (Config::array('tour')['schedule.index'] as $step) {
        $response->assertSee('data-tour="'.$step['target'].'"', false);
    }
});
