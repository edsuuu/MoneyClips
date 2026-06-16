<?php

declare(strict_types=1);

use App\Livewire\Videos\Create;
use App\Models\Status;
use App\Models\User;
use App\Models\Video;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->user = User::factory()->create();
    Auth::login($this->user);
});

test('create page shows the video url form', function (): void {
    $this->actingAs($this->user)
        ->get(route('videos.create'))
        ->assertOk()
        ->assertSee('URL do vídeo')
        ->assertSee('Salvar vídeo');
});

test('start queues processing and redirects to the editor', function (): void {
    Queue::fake();

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('url', 'https://www.youtube.com/watch?v=12345')
        ->call('start')
        ->assertHasNoErrors()
        ->assertRedirectContains('/videos/')
        ->assertRedirectContains('/editor');

    $video = Video::query()->latest()->first();

    expect($video)->not->toBeNull();
    expect($video?->url)->toBe('https://www.youtube.com/watch?v=12345');
    expect($video?->status_id)->toBe(Status::idFor('queued'));

});

test('start rejects an invalid url', function (): void {
    Queue::fake();

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('url', 'not-a-url')
        ->call('start')
        ->assertHasErrors(['url']);

    expect(Video::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});
