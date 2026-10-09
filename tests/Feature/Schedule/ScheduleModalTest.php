<?php

declare(strict_types=1);

use App\Enums\PostStatusEnum;
use App\Enums\RoleEnum;
use App\Livewire\Videos\Index;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\YoutubeShort;
use App\Services\Posting\PostSchedulerService;
use Database\Seeders\Seeder001Roles;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

function modalAccount(User $user, string $platform, array $attributes = []): SocialAccount
{
    return SocialAccount::query()->create([
        'user_id' => $user->id,
        'platform' => $platform,
        'name' => $platform === 'tiktok' ? '@unkvoid_clips' : 'Unkvoid Clips',
        'is_active' => true,
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

    $this->tiktok = modalAccount($this->creator, 'tiktok');
    $this->youtube = modalAccount($this->creator, 'youtube');
    $this->short = YoutubeShort::factory()->for($this->creator)->ready()->create(['title' => 'O lado podre do crime']);

    $this->actingAs($this->creator);
});

it('opens with every free active account checked and schedules on the next good time in one click', function (): void {
    modalAccount($this->creator, 'tiktok', ['name' => '@desligada', 'is_active' => false]);

    Livewire::test(Index::class)
        ->assertSee('Agendar')
        ->call('openSchedule', $this->short->id)
        ->assertSet('scheduleAccountIds', [$this->tiktok->id, $this->youtube->id])
        ->assertSee('Agendar postagem')
        ->assertSee('TikTok · @unkvoid_clips')
        ->assertSee('YouTube · Unkvoid Clips')
        ->assertSee('pelo navegador')
        ->assertSee('API oficial')
        ->assertDontSee('@desligada')
        ->assertSee('No próximo horário bom')
        ->assertSee('hoje às 14:00')
        ->assertSee('Horários bons: 8h, 10h, 14h, 17h, 20h e 21h30.')
        ->call('saveSchedule')
        ->assertHasNoErrors()
        ->assertSet('schedulingShortId', null)
        ->assertDispatched('toast', message: 'Agendado: hoje às 14:00 no TikTok e no YouTube.', variant: 'success')
        ->assertSee('Agendado')
        ->assertDontSee('Agendar postagem');

    $posts = SocialPost::query()->where('youtube_short_id', $this->short->id)->get();
    expect($posts)->toHaveCount(2)
        ->and($posts->every(fn (SocialPost $post): bool => $post->status === PostStatusEnum::Scheduled && $post->scheduled_for->format('Y-m-d H:i') === '2026-10-08 14:00'))->toBeTrue();
});

it('lists the next slot per account when they differ', function (): void {
    SocialPost::query()->create([
        'youtube_short_id' => YoutubeShort::factory()->for($this->creator)->create()->id,
        'social_account_id' => $this->youtube->id,
        'scheduled_for' => '2026-10-08 14:00',
        'status' => PostStatusEnum::Scheduled,
    ]);

    Livewire::test(Index::class)
        ->call('openSchedule', $this->short->id)
        ->assertSee('TikTok hoje às 14:00 · YouTube hoje às 17:00')
        ->call('saveSchedule')
        ->assertDispatched('toast', message: 'Agendado: TikTok hoje às 14:00 · YouTube hoje às 17:00.', variant: 'success');
});

it('schedules at a chosen date and time and refuses one in the past', function (): void {
    Livewire::test(Index::class)
        ->call('openSchedule', $this->short->id)
        ->set('scheduleAccountIds', [(string) $this->tiktok->id])
        ->set('scheduleWhen', 'custom')
        ->set('scheduleAt', '2026-10-08 09:00')
        ->call('saveSchedule')
        ->assertHasErrors(['scheduleAt'])
        ->assertSee('Escolha uma data e hora no futuro.')
        ->set('scheduleAt', '2026-10-08T09:00')
        ->call('saveSchedule')
        ->assertHasErrors(['scheduleAt'])
        ->set('scheduleAt', '2026-10-10T19:45')
        ->call('saveSchedule')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Agendado: sáb, 10/10 às 19:45 no TikTok.', variant: 'success');

    $post = SocialPost::query()->sole();
    expect($post->social_account_id)->toBe($this->tiktok->id)
        ->and($post->scheduled_for->format('Y-m-d H:i'))->toBe('2026-10-10 19:45');
});

it('asks for at least one account', function (): void {
    Livewire::test(Index::class)
        ->call('openSchedule', $this->short->id)
        ->set('scheduleAccountIds', [])
        ->call('saveSchedule')
        ->assertHasErrors(['scheduleAccountIds'])
        ->assertSee('Escolha pelo menos uma conta.');

    expect(SocialPost::query()->count())->toBe(0);
});

it('disables accounts that already have the Short and hides Agendar once every account has it', function (): void {
    SocialPost::query()->create([
        'youtube_short_id' => $this->short->id,
        'social_account_id' => $this->tiktok->id,
        'scheduled_for' => '2026-10-08 20:00',
        'status' => PostStatusEnum::Scheduled,
    ]);

    $component = Livewire::test(Index::class)
        ->assertViewHas('ready', fn (array $cards): bool => $cards[0]['canSchedule'] === true && $cards[0]['posts'][0]['detail'] === 'hoje às 20:00')
        ->call('openSchedule', $this->short->id)
        ->assertSet('scheduleAccountIds', [$this->youtube->id])
        ->assertSee('Já agendado · qui 20:00')
        ->set('scheduleAccountIds', [$this->tiktok->id])
        ->call('saveSchedule')
        ->assertHasErrors(['scheduleAccountIds'])
        ->assertSee('Esse Short já está agendado nessa conta.')
        ->set('scheduleAccountIds', [$this->youtube->id])
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $component->assertViewHas('ready', fn (array $cards): bool => $cards[0]['canSchedule'] === false && count($cards[0]['posts']) === 2);

    expect(SocialPost::query()->count())->toBe(2);
});

it('shows a disconnected account disabled and the empty state without accounts', function (): void {
    $this->tiktok->update(['session_status' => SocialAccount::SESSION_INVALID]);

    Livewire::test(Index::class)
        ->call('openSchedule', $this->short->id)
        ->assertSet('scheduleAccountIds', [$this->youtube->id])
        ->assertSee('Desconectada · reconecte em Contas');

    SocialAccount::query()->update(['is_active' => false]);

    Livewire::test(Index::class)
        ->assertViewHas('ready', fn (array $cards): bool => $cards[0]['canSchedule'] === false)
        ->call('openSchedule', $this->short->id)
        ->assertSee('Nenhuma conta pronta para postar.')
        ->assertSee('Ir para Contas')
        ->call('saveSchedule')
        ->assertHasErrors(['scheduleAccountIds']);
});

it('reuses a canceled row instead of breaking the Short × account unique', function (): void {
    $canceled = SocialPost::query()->create([
        'youtube_short_id' => $this->short->id,
        'social_account_id' => $this->tiktok->id,
        'scheduled_for' => '2026-10-07 20:00',
        'status' => PostStatusEnum::Canceled,
    ]);

    $scheduler = resolve(PostSchedulerService::class);
    $post = $scheduler->schedule($this->short, $this->tiktok);

    expect($post?->id)->toBe($canceled->id)
        ->and($post?->status)->toBe(PostStatusEnum::Scheduled)
        ->and($post?->scheduled_for->format('Y-m-d H:i'))->toBe('2026-10-08 14:00')
        ->and($scheduler->schedule($this->short, $this->tiktok))->toBeNull()
        ->and(SocialPost::query()->count())->toBe(1);
});

it('keeps a creator away from shorts and accounts of other users', function (): void {
    $foreignShort = YoutubeShort::factory()->for($this->other)->ready()->create();
    $foreignAccount = modalAccount($this->other, 'tiktok', ['name' => '@alheia']);

    Livewire::test(Index::class)->call('openSchedule', $foreignShort->id)->assertForbidden();

    Livewire::test(Index::class)
        ->call('openSchedule', $this->short->id)
        ->assertDontSee('@alheia')
        ->set('scheduleAccountIds', [$foreignAccount->id])
        ->call('saveSchedule')
        ->assertHasErrors(['scheduleAccountIds']);

    expect(SocialPost::query()->count())->toBe(0);
});

it('shows the state lines on the ready card and cancels from there', function (): void {
    $post = SocialPost::query()->create([
        'youtube_short_id' => $this->short->id,
        'social_account_id' => $this->youtube->id,
        'scheduled_for' => '2026-10-08 20:00',
        'status' => PostStatusEnum::Scheduled,
    ]);

    Livewire::test(Index::class)
        ->assertSee('Prontos para postar')
        ->assertSee('Revisar título')
        ->assertSeeHtml('wire:confirm="Cancelar a postagem de &quot;O lado podre do crime&quot; no YouTube?"')
        ->call('cancelPost', $post->id)
        ->assertDispatched('toast', message: 'Postagem cancelada.', variant: 'success')
        ->assertViewHas('ready', fn (array $cards): bool => $cards[0]['posts'] === []);
});
