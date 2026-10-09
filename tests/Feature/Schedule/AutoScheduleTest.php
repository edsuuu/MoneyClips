<?php

declare(strict_types=1);

use App\Enums\PostStatusEnum;
use App\Enums\RoleEnum;
use App\Enums\SocialAccountModeEnum;
use App\Livewire\Accounts\Index as AccountsIndex;
use App\Livewire\Schedule\Index as ScheduleIndex;
use App\Livewire\Videos\Index as VideosIndex;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\YoutubeShort;
use App\Services\Posting\PostSchedulerService;
use Database\Seeders\Seeder001Roles;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function autoAccount(User $user, string $platform, SocialAccountModeEnum $mode = SocialAccountModeEnum::Auto): SocialAccount
{
    $account = new SocialAccount(['user_id' => $user->id, 'platform' => $platform, 'name' => $platform === 'tiktok' ? '@auto' : 'Canal Auto']);
    $account->applyMode($mode);
    $account->save();

    return $account;
}

beforeEach(function (): void {
    Date::setTestNow('2026-10-08 10:30:00');
    config([
        'posting.times' => ['08:00', '10:00', '14:00', '17:00', '20:00', '21:30'],
        'posting.per_day' => 6,
        'posting.min_gap_minutes' => 60,
        'services.discord.webhook' => 'https://discord.test/webhook',
    ]);
    Http::fake(['discord.test/*' => Http::response()]);

    $this->seed(Seeder001Roles::class);
    $this->creator = User::factory()->create()->assignRole(RoleEnum::Creator);
    $this->actingAs($this->creator);
});

it('maps the three modes to the account booleans with one control in /contas', function (): void {
    $account = autoAccount($this->creator, 'tiktok', SocialAccountModeEnum::Manual);

    Livewire::test(AccountsIndex::class)
        ->assertSee('Desligada')
        ->assertSee('Automática')
        ->assertSee('Você escolhe quando cada Short sai.')
        ->call('setMode', $account->id, 'auto')
        ->assertDispatched('toast', message: 'Conta em modo Automática.', variant: 'success')
        ->assertSee('Todo Short pronto é agendado sozinho nos horários bons.');
    expect($account->refresh()->is_active)->toBeTrue()->and($account->auto_schedule)->toBeTrue()
        ->and($account->mode())->toBe(SocialAccountModeEnum::Auto);

    Livewire::test(AccountsIndex::class)->call('setMode', $account->id, 'off');
    expect($account->refresh()->is_active)->toBeFalse()->and($account->auto_schedule)->toBeFalse();

    Livewire::test(AccountsIndex::class)->call('setMode', $account->id, 'manual');
    expect($account->refresh()->mode())->toBe(SocialAccountModeEnum::Manual);

    Livewire::test(AccountsIndex::class)->call('setMode', $account->id, 'turbo');
    expect($account->refresh()->mode())->toBe(SocialAccountModeEnum::Manual);
});

it('schedules a Short marked as ready on every Automatic account, and only there', function (): void {
    $auto = autoAccount($this->creator, 'tiktok');
    $manual = autoAccount($this->creator, 'youtube', SocialAccountModeEnum::Manual);
    $short = YoutubeShort::factory()->for($this->creator)->create(['ready_at' => null]);

    Livewire::test(VideosIndex::class)
        ->call('markReady', $short->id)
        ->assertDispatched('toast', message: 'Vídeo marcado como pronto. Agendado: hoje às 14:00 no TikTok.', variant: 'success');

    $post = SocialPost::query()->sole();
    expect($post->social_account_id)->toBe($auto->id)
        ->and($post->status)->toBe(PostStatusEnum::Scheduled)
        ->and($post->scheduled_for->format('Y-m-d H:i'))->toBe('2026-10-08 14:00')
        ->and(SocialPost::query()->where('social_account_id', $manual->id)->exists())->toBeFalse();

    Livewire::test(VideosIndex::class)
        ->call('markReady', $short->id)
        ->assertDispatched('toast', message: 'Vídeo marcado como pronto.', variant: 'success');
    expect(SocialPost::query()->count())->toBe(1);
});

it('fills what is missing, oldest ready first, without undoing a cancel or touching manual and ownerless Shorts', function (): void {
    $auto = autoAccount($this->creator, 'tiktok');
    autoAccount($this->creator, 'youtube', SocialAccountModeEnum::Manual);
    $newer = YoutubeShort::factory()->for($this->creator)->create(['ready_at' => '2026-10-08 09:00']);
    $older = YoutubeShort::factory()->for($this->creator)->create(['ready_at' => '2026-10-07 09:00']);
    $canceled = YoutubeShort::factory()->for($this->creator)->ready()->create();
    SocialPost::query()->create(['youtube_short_id' => $canceled->id, 'social_account_id' => $auto->id, 'scheduled_for' => '2026-10-08 08:00', 'status' => PostStatusEnum::Canceled]);
    YoutubeShort::factory()->ready()->create();
    YoutubeShort::factory()->for($this->creator)->ready()->create(['posted_tiktok_at' => now()]);
    YoutubeShort::factory()->for($this->creator)->create(['ready_at' => null]);
    YoutubeShort::factory()->for($this->creator)->ready()->notDownloaded()->create();

    $this->artisan('posts:fill')->assertSuccessful();

    $posts = SocialPost::query()->where('status', PostStatusEnum::Scheduled)->orderBy('scheduled_for')->get();
    expect($posts->pluck('youtube_short_id')->all())->toBe([$older->id, $newer->id])
        ->and($posts->pluck('scheduled_for')->map->format('H:i')->all())->toBe(['14:00', '17:00'])
        ->and($posts->every(fn (SocialPost $post): bool => $post->social_account_id === $auto->id))->toBeTrue();

    $this->artisan('posts:fill')->assertSuccessful();
    expect(SocialPost::query()->count())->toBe(3);
});

it('warns Discord once a day when the stock covers less than one day of posting', function (): void {
    $thin = autoAccount($this->creator, 'tiktok');
    $full = autoAccount($this->creator, 'youtube');
    foreach (range(1, 6) as $day) {
        SocialPost::query()->create([
            'youtube_short_id' => YoutubeShort::factory()->for($this->creator)->create()->id,
            'social_account_id' => $full->id,
            'scheduled_for' => now()->addDays($day),
            'status' => PostStatusEnum::Scheduled,
        ]);
    }

    YoutubeShort::factory()->for($this->creator)->ready()->create();

    $this->artisan('posts:fill')->assertSuccessful();
    $this->artisan('posts:fill')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['embeds'][0]['description'], '@auto (tiktok): 1 agendado(s), menos de 1 dia de postagem (6 por dia)'));
    expect($thin->socialPosts()->count())->toBe(1);
});

it('drops the short agenda warning once an account is Automatic', function (): void {
    $manual = autoAccount($this->creator, 'tiktok', SocialAccountModeEnum::Manual);
    SocialPost::query()->create([
        'youtube_short_id' => YoutubeShort::factory()->for($this->creator)->ready()->create()->id,
        'social_account_id' => $manual->id,
        'scheduled_for' => '2026-10-08 20:00',
        'status' => PostStatusEnum::Scheduled,
    ]);

    Livewire::test(ScheduleIndex::class)->assertSee('A agenda acaba hoje. Marque mais Shorts como prontos ou ligue o modo Automático.');

    $manual->update(['auto_schedule' => true]);
    Livewire::test(ScheduleIndex::class)->assertDontSee('A agenda acaba');
});

it('schedules on TikTok a Short that already went out only on YouTube', function (): void {
    $tiktok = autoAccount($this->creator, 'tiktok');
    $youtube = autoAccount($this->creator, 'youtube');
    $short = YoutubeShort::factory()->for($this->creator)->ready()->posted()->create();

    $this->artisan('posts:fill')->assertSuccessful();

    expect(SocialPost::query()->where('social_account_id', $tiktok->id)->sole()->youtube_short_id)->toBe($short->id)
        ->and(SocialPost::query()->where('social_account_id', $youtube->id)->exists())->toBeFalse();
});

it('never auto-schedules a Short without video', function (): void {
    autoAccount($this->creator, 'tiktok');
    $short = YoutubeShort::factory()->for($this->creator)->ready()->notDownloaded()->create();

    expect(resolve(PostSchedulerService::class)->autoSchedule($short))->toBe([])
        ->and(SocialPost::query()->count())->toBe(0);
});

it('fills the account right away when it turns Automatic', function (): void {
    $account = autoAccount($this->creator, 'tiktok', SocialAccountModeEnum::Manual);
    YoutubeShort::factory()->for($this->creator)->ready()->count(2)->create();

    Livewire::test(AccountsIndex::class)
        ->call('setMode', $account->id, 'auto')
        ->assertDispatched('toast', message: 'Conta em modo Automática. 2 Shorts agendados.', variant: 'success');

    expect($account->socialPosts()->where('status', PostStatusEnum::Scheduled)->count())->toBe(2);
});

it('refuses Automatic on a disconnected account', function (): void {
    $account = autoAccount($this->creator, 'tiktok', SocialAccountModeEnum::Manual);
    $account->update(['session_status' => SocialAccount::SESSION_INVALID]);

    Livewire::test(AccountsIndex::class)
        ->call('setMode', $account->id, 'auto')
        ->assertDispatched('toast', message: 'Reconecte a conta para o Automático funcionar.', variant: 'danger');

    expect($account->refresh()->mode())->toBe(SocialAccountModeEnum::Manual);
});

it('takes the scheduled posts out of an Automatic account turned off, without Discord, and fills them back when it turns Automatic again', function (): void {
    config(['posting.per_day' => 2]);
    $account = autoAccount($this->creator, 'tiktok');
    $other = autoAccount($this->creator, 'youtube');
    YoutubeShort::factory()->for($this->creator)->ready()->count(2)->create();
    $this->artisan('posts:fill')->assertSuccessful();

    Livewire::test(AccountsIndex::class)
        ->assertSeeHtml('wire:confirm="2 postagens agendadas serão canceladas."')
        ->call('setMode', $account->id, 'off')
        ->assertDispatched('toast', message: 'Conta em modo Desligada. 2 postagens canceladas.', variant: 'success');

    expect($account->socialPosts()->count())->toBe(0)
        ->and($other->socialPosts()->where('status', PostStatusEnum::Scheduled)->count())->toBe(2);

    $this->artisan('posts:fill')->assertSuccessful();
    expect($account->socialPosts()->count())->toBe(0);

    Livewire::test(AccountsIndex::class)
        ->call('setMode', $account->id, 'auto')
        ->assertDispatched('toast', message: 'Conta em modo Automática. 2 Shorts agendados.', variant: 'success');

    expect($account->socialPosts()->where('status', PostStatusEnum::Scheduled)->count())->toBe(2);
    Http::assertNothingSent();
});

it('keeps the owner cancel when the account turns Automatic again', function (SocialAccountModeEnum $via): void {
    $account = autoAccount($this->creator, 'tiktok');
    $short = YoutubeShort::factory()->for($this->creator)->ready()->create();
    $this->artisan('posts:fill')->assertSuccessful();
    $post = $account->socialPosts()->sole();
    Livewire::test(ScheduleIndex::class)->call('cancelPost', $post->id);

    $this->artisan('posts:fill')->assertSuccessful();
    Livewire::test(AccountsIndex::class)->call('setMode', $account->id, $via->value);
    Livewire::test(AccountsIndex::class)->call('setMode', $account->id, 'auto');

    expect($account->socialPosts()->sole()->status)->toBe(PostStatusEnum::Canceled)
        ->and($post->youtube_short_id)->toBe($short->id);
})->with([SocialAccountModeEnum::Manual, SocialAccountModeEnum::Off]);

it('cancels the scheduled posts of a Manual account turned off, so none of them fails or warns Discord', function (): void {
    $account = autoAccount($this->creator, 'tiktok', SocialAccountModeEnum::Manual);
    $short = YoutubeShort::factory()->for($this->creator)->ready()->create();
    resolve(PostSchedulerService::class)->schedule($short, $account);

    Livewire::test(AccountsIndex::class)
        ->assertSeeHtml('wire:confirm="1 postagem agendada será cancelada."')
        ->call('setMode', $account->id, 'off')
        ->assertDispatched('toast', message: 'Conta em modo Desligada. 1 postagem cancelada.', variant: 'success');

    expect($account->socialPosts()->sole()->status)->toBe(PostStatusEnum::Canceled);

    $this->travel(2)->days();
    $this->artisan('posts:dispatch')->assertSuccessful();

    expect($account->socialPosts()->sole()->status)->toBe(PostStatusEnum::Canceled);
    Http::assertNothingSent();
});

it('measures the low stock against the posts a day can really hold', function (): void {
    config(['posting.times' => ['09:00', '20:00', '21:00']]);
    $account = autoAccount($this->creator, 'tiktok');
    foreach (range(1, 3) as $day) {
        SocialPost::query()->create([
            'youtube_short_id' => YoutubeShort::factory()->for($this->creator)->create()->id,
            'social_account_id' => $account->id,
            'scheduled_for' => now()->addDays($day),
            'status' => PostStatusEnum::Scheduled,
        ]);
    }

    $this->artisan('posts:fill')->assertSuccessful();

    Http::assertNothingSent();
});
