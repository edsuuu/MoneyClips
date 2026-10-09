<?php

declare(strict_types=1);

use App\Livewire\Videos\Index;
use App\Models\User;
use App\Models\VideoCutEdit;
use App\Models\YoutubeShort;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('splits the stock into downloaded and ready sections', function (): void {
    $downloaded = YoutubeShort::factory()->for($this->user)->create(['ready_at' => null]);
    $ready = YoutubeShort::factory()->for($this->user)->ready()->create();
    YoutubeShort::factory()->for($this->user)->posted()->create();

    Livewire::test(Index::class)
        ->assertSee($downloaded->title)
        ->assertSee($ready->title)
        ->assertViewHas('downloaded', fn (array $items): bool => count($items) === 1)
        ->assertViewHas('ready', fn (array $items): bool => count($items) === 1)
        ->assertViewHas('posted', fn (array $items): bool => count($items) === 1);
});

it('shows the duration under the card title and falls back to the id when it is unknown', function (): void {
    YoutubeShort::factory()->for($this->user)->ready()->create(['youtube_id' => 'reframe-abc', 'duration_seconds' => 78]);
    YoutubeShort::factory()->for($this->user)->ready()->create(['youtube_id' => 'dQw4w9WgXcQ']);

    Livewire::test(Index::class)
        ->assertSee('1:18')
        ->assertDontSee('reframe-abc')
        ->assertSee('dQw4w9WgXcQ');
});

it('requires hashtags before marking a video as ready', function (): void {
    $short = YoutubeShort::factory()->for($this->user)->create(['hashtags' => [], 'ready_at' => null]);

    Livewire::test(Index::class)->call('markReady', $short->id);
    expect($short->refresh()->ready_at)->toBeNull();

    $short->update(['hashtags' => ['#shorts']]);
    Livewire::test(Index::class)->call('markReady', $short->id);
    expect($short->refresh()->ready_at)->not->toBeNull();
});

it('saves title and hashtags from the review modal', function (): void {
    $short = YoutubeShort::factory()->for($this->user)->create();

    Livewire::test(Index::class)
        ->call('openEdit', $short->id)
        ->set('editTitle', 'Novo título')
        ->set('editHashtags', 'shorts, #podcast')
        ->call('saveEdit');

    $short->refresh();
    expect($short->title)->toBe('Novo título')
        ->and($short->hashtags)->toBe(['#shorts', '#podcast']);
});

it('shows the image credits of the edit in the review modal, read only', function (): void {
    $credited = YoutubeShort::factory()->for($this->user)->create();
    $plain = YoutubeShort::factory()->for($this->user)->create();
    VideoCutEdit::factory()->create(['youtube_short_id' => $credited->id, 'spec' => ['images' => [
        ['asset_id' => 'a', 'key' => 'assets/image/a.jpg', 'size' => 'card', 't' => [1.0, 2.0], 'credit' => 'Imagem: Fulano, CC BY-SA https://commons.wikimedia.org/wiki/File:A.jpg'],
        ['asset_id' => 'b', 'key' => 'assets/image/b.jpg', 'size' => 'small', 't' => [9.0, 10.0], 'credit' => null],
    ]]]);

    Livewire::test(Index::class)
        ->call('openEdit', $credited->id)
        ->assertSee('CRÉDITOS')
        ->assertViewHas('editingCredits', 'Imagem: Fulano, CC BY-SA https://commons.wikimedia.org/wiki/File:A.jpg')
        ->call('openEdit', $plain->id)
        ->assertDontSee('CRÉDITOS')
        ->assertViewHas('editingCredits', '');
});
