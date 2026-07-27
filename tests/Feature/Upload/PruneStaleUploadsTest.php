<?php

declare(strict_types=1);

use App\Enums\VideoStatusEnum;
use App\Models\Video;
use App\Services\Upload\MultipartUploadInterface;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    Date::setTestNow('2026-07-20 04:30:00');

    $this->mock(MultipartUploadInterface::class, function ($mock): void {
        $mock->shouldReceive('abort')->andReturnNull();
    });
});

it('falha empacotamento que ficou sem sinal e preserva o que ainda progride', function (): void {
    $travado = Video::factory()->packaging()->create([
        'progress' => 42,
        'updated_at' => now()->subHours(7),
    ]);

    $vivo = Video::factory()->packaging()->create([
        'progress' => 80,
        'updated_at' => now()->subMinutes(3),
    ]);

    // Encode longo mas ATIVO: começou há dias e segue mandando progresso. O
    // corte é pelo silêncio (updated_at), não pela idade — senão o comando
    // mataria justamente os vídeos grandes, que são os que mais demoram.
    $longo = Video::factory()->packaging()->create([
        'progress' => 55,
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subMinutes(1),
    ]);

    $this->artisan('uploads:prune-stale')->assertSuccessful();

    expect($travado->refresh()->status)->toBe(VideoStatusEnum::Failed)
        ->and($travado->error)->toContain('sem sinal')
        ->and($vivo->refresh()->status)->toBe(VideoStatusEnum::Packaging)
        ->and($longo->refresh()->status)->toBe(VideoStatusEnum::Packaging);
});

it('falha upload cujo job de empacotamento nunca rodou', function (): void {
    $orfao = Video::factory()->uploaded()->create(['updated_at' => now()->subHours(7)]);

    $this->artisan('uploads:prune-stale')->assertSuccessful();

    expect($orfao->refresh()->status)->toBe(VideoStatusEnum::Failed);
});

it('falha download do youtube que ficou sem webhook', function (): void {
    $orfao = Video::factory()->downloading()->create(['updated_at' => now()->subHours(7)]);
    $vivo = Video::factory()->downloading()->create(['updated_at' => now()->subMinutes(3)]);

    $this->artisan('uploads:prune-stale')->assertSuccessful();

    expect($orfao->refresh()->status)->toBe(VideoStatusEnum::Failed)
        ->and($orfao->error)->toContain('Download do YouTube')
        ->and($vivo->refresh()->status)->toBe(VideoStatusEnum::Downloading);
});

it('marca como falha em vez de apagar, porque o binário segue no storage', function (): void {
    $travado = Video::factory()->packaging()->create(['updated_at' => now()->subHours(7)]);

    $this->artisan('uploads:prune-stale')->assertSuccessful();

    expect(Video::query()->whereKey($travado->id)->exists())->toBeTrue();
});

it('não toca em vídeo que já chegou a um estado terminal', function (): void {
    $pronto = Video::factory()->ready()->create(['updated_at' => now()->subMonth()]);

    $this->artisan('uploads:prune-stale')->assertSuccessful();

    expect($pronto->refresh()->status)->toBe(VideoStatusEnum::Ready);
});

it('continua apagando o multipart abandonado antes de qualquer envio', function (): void {
    $abandonado = Video::factory()->create(['created_at' => now()->subHours(25)]);

    $this->artisan('uploads:prune-stale')->assertSuccessful();

    expect(Video::query()->whereKey($abandonado->id)->exists())->toBeFalse();
});
