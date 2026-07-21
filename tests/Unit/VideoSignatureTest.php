<?php

declare(strict_types=1);

use App\Services\Upload\VideoSignatureService;

beforeEach(function (): void {
    $this->signatures = new VideoSignatureService;
});

it('aceita os containers que a aplicação suporta', function (string $header): void {
    expect($this->signatures->looksLikeVideo($header))->toBeTrue();
})->with([
    'mp4' => "\x00\x00\x00\x18ftypmp42",
    'mov' => "\x00\x00\x00\x14ftypqt  ",
    'webm' => "\x1A\x45\xDF\xA3\x01\x00\x00\x00",
]);

it('recusa conteúdo que só finge ser vídeo', function (string $header): void {
    expect($this->signatures->looksLikeVideo($header))->toBeFalse();
})->with([
    'zip renomeado pra .mp4' => "PK\x03\x04\x14\x00\x00\x00\x08\x00",
    'executável' => "\x7FELF\x02\x01\x01\x00",
    'html' => '<!doctype html>',
    'vazio' => '',
    'curto demais' => "\x00\x00",
    'ftyp fora de posição' => "ftyp\x00\x00\x00\x18",
]);
