<?php

declare(strict_types=1);

use App\Livewire\Uploads\Index;
use App\Models\User;
use App\Models\Video;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lista apenas os vídeos do próprio usuário', function (): void {
    $mine = Video::factory()->create(['user_id' => $this->user->id]);
    $theirs = Video::factory()->create(['user_id' => User::factory()->create()->id]);

    Livewire::test(Index::class)
        ->assertSee($mine->uuid)
        ->assertDontSee($theirs->uuid);
});

it('não resolve o vídeo alheio em nenhuma rota', function (): void {
    $theirs = Video::factory()->ready()->create(['user_id' => User::factory()->create()->id]);

    $this->get(route('uploads.show', $theirs->uuid))->assertNotFound();
    $this->getJson(sprintf('/uploads/%s/parts', $theirs->uuid))->assertNotFound();
    $this->postJson(sprintf('/uploads/%s/parts', $theirs->uuid), ['part_numbers' => [1]])->assertNotFound();
    $this->postJson(sprintf('/uploads/%s/complete', $theirs->uuid), ['parts' => []])->assertNotFound();
    $this->deleteJson('/uploads/'.$theirs->uuid)->assertNotFound();
    $this->get(route('hls.master', $theirs->uuid))->assertNotFound();
});

it('não apaga o vídeo alheio pelo componente', function (): void {
    $theirs = Video::factory()->create(['user_id' => User::factory()->create()->id]);

    Livewire::test(Index::class)->call('delete', $theirs->uuid);

    expect(Video::query()->whereKey($theirs->id)->exists())->toBeTrue();
});
