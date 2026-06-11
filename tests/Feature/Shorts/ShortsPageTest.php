<?php

declare(strict_types=1);

use App\Jobs\PostYoutubeShortJob;
use App\Livewire\Shorts\Index;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('shorts page renders with stock metrics', function (): void {
    YoutubeShort::factory()->count(2)->create();
    YoutubeShort::factory()->posted()->create();

    $this->actingAs($this->user)
        ->get(route('shorts.index'))
        ->assertOk()
        ->assertSee('Estoque de Shorts')
        ->assertSee('Disponíveis')
        ->assertSee('Postados');
});

test('post now queues the job for an available short', function (): void {
    Queue::fake();

    SocialAccount::query()->create([
        'platform' => 'youtube',
        'name' => 'Canal de Teste',
        'is_active' => true,
        'access_token' => 'token',
    ]);

    $short = YoutubeShort::factory()->create();

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->call('postNow', $short->id)
        ->assertHasNoErrors();

    Queue::assertPushed(PostYoutubeShortJob::class, fn (PostYoutubeShortJob $job): bool => $job->youtubeShortId === $short->id);
});

test('post now refuses when no youtube account is connected', function (): void {
    Queue::fake();

    $short = YoutubeShort::factory()->create();

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->call('postNow', $short->id)
        ->assertHasNoErrors();

    Queue::assertNothingPushed();
});

test('post now refuses an already posted short', function (): void {
    Queue::fake();

    SocialAccount::query()->create([
        'platform' => 'youtube',
        'name' => 'Canal de Teste',
        'is_active' => true,
        'access_token' => 'token',
    ]);

    $short = YoutubeShort::factory()->posted()->create();

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->call('postNow', $short->id)
        ->assertHasNoErrors();

    Queue::assertNothingPushed();
});
