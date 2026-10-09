<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Enums\VideoCutStatusEnum;
use App\Livewire\Accounts\Index as AccountsIndex;
use App\Livewire\Uploads\Index as UploadsIndex;
use App\Livewire\Videos\Index as VideosIndex;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use App\Models\YoutubeShort;
use Database\Seeders\Seeder001Roles;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(Seeder001Roles::class);
    $this->creator = User::factory()->create()->assignRole(RoleEnum::Creator);
    $this->admin = User::factory()->create()->assignRole(RoleEnum::Admin);
    $this->other = User::factory()->create()->assignRole(RoleEnum::Creator);
});

it('scopes the stock to the owner and lets admin see everything', function (): void {
    $own = YoutubeShort::factory()->for($this->creator)->create(['title' => 'Short meu']);
    $foreign = YoutubeShort::factory()->for($this->other)->create(['title' => 'Short alheio']);

    $this->actingAs($this->creator);
    Livewire::test(VideosIndex::class)->assertSee('Short meu')->assertDontSee('Short alheio');
    Livewire::test(VideosIndex::class)->call('openEdit', $foreign->id)->assertForbidden();
    Livewire::test(VideosIndex::class)->call('markReady', $foreign->id)->assertForbidden();
    Livewire::test(VideosIndex::class)->call('redo', $foreign->id, 'mais memes')->assertForbidden();
    expect($foreign->refresh()->ready_at)->toBeNull();

    $this->actingAs($this->admin);
    Livewire::test(VideosIndex::class)->assertSee('Short meu')->assertSee('Short alheio')
        ->call('markReady', $foreign->id)->assertOk();
    expect($foreign->refresh()->ready_at)->not->toBeNull()
        ->and($own->refresh()->ready_at)->toBeNull();
});

it('only lets admin start a channel download', function (): void {
    $this->actingAs($this->creator);
    Livewire::test(VideosIndex::class)->assertDontSee('Novo download')
        ->call('startDownload')->assertForbidden();

    $this->actingAs($this->admin);
    Livewire::test(VideosIndex::class)->assertSee('Novo download');
});

it('scopes uploads to the owner and answers 403 on a foreign video', function (): void {
    $own = Video::factory()->ready()->create(['user_id' => $this->creator->id]);
    $foreign = Video::factory()->ready()->create(['user_id' => $this->other->id]);
    $foreignCut = $foreign->cuts()->create(['start_seconds' => 1, 'end_seconds' => 10, 'status' => VideoCutStatusEnum::Ready]);

    $this->actingAs($this->creator);
    Livewire::test(UploadsIndex::class)->assertSee($own->uuid)->assertDontSee($foreign->uuid);
    $this->get('/meus-uploads/'.$foreign->uuid)->assertForbidden();
    $this->get('/editor-de-video/'.$foreignCut->uuid)->assertForbidden();
    $this->get('/hls/'.$foreign->uuid.'/master.m3u8')->assertNotFound();

    $this->actingAs($this->admin);
    Livewire::test(UploadsIndex::class)->assertSee($own->uuid)->assertSee($foreign->uuid);
    $this->get('/meus-uploads/'.$foreign->uuid)->assertOk();
    $this->get('/editor-de-video/'.$foreignCut->uuid)->assertOk();
});

it('scopes social accounts to the owner', function (): void {
    SocialAccount::query()->create(['user_id' => $this->creator->id, 'platform' => 'tiktok', 'name' => '@minha', 'is_active' => true]);
    $foreign = SocialAccount::query()->create(['user_id' => $this->other->id, 'platform' => 'tiktok', 'name' => '@alheia', 'is_active' => true]);

    $this->actingAs($this->creator);
    Livewire::test(AccountsIndex::class)->assertSee('@minha')->assertDontSee('@alheia');
    Livewire::test(AccountsIndex::class)->call('setMode', $foreign->id, 'off')->assertForbidden();
    Livewire::test(AccountsIndex::class)->call('delete', $foreign->id)->assertForbidden();
    expect($foreign->refresh()->is_active)->toBeTrue();

    $this->actingAs($this->admin);
    Livewire::test(AccountsIndex::class)->assertSee('@minha')->assertSee('@alheia')
        ->call('setMode', $foreign->id, 'off')->assertOk();
    expect($foreign->refresh()->is_active)->toBeFalse();
});
