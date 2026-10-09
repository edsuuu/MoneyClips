<?php

declare(strict_types=1);

use App\Livewire\Videos\Index;
use App\Models\User;
use App\Models\VideoCutEdit;
use App\Models\YoutubeShort;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('splits the stock into downloaded and ready sections', function (): void {
    $downloaded = YoutubeShort::factory()->create(['ready_at' => null]);
    $ready = YoutubeShort::factory()->ready()->create();
    YoutubeShort::factory()->posted()->create();

    Livewire::test(Index::class)
        ->assertSee($downloaded->title)
        ->assertSee($ready->title)
        ->assertViewHas('downloaded', fn (array $items): bool => count($items) === 1)
        ->assertViewHas('ready', fn (array $items): bool => count($items) === 1)
        ->assertViewHas('posted', fn (array $items): bool => count($items) === 1);
});

it('requires hashtags before marking a video as ready', function (): void {
    $short = YoutubeShort::factory()->create(['hashtags' => [], 'ready_at' => null]);

    Livewire::test(Index::class)->call('markReady', $short->id);
    expect($short->refresh()->ready_at)->toBeNull();

    $short->update(['hashtags' => ['#shorts']]);
    Livewire::test(Index::class)->call('markReady', $short->id);
    expect($short->refresh()->ready_at)->not->toBeNull();
});

it('saves title and hashtags from the review modal', function (): void {
    $short = YoutubeShort::factory()->create();

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
    $credited = YoutubeShort::factory()->create();
    $plain = YoutubeShort::factory()->create();
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
