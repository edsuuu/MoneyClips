<?php

declare(strict_types=1);

namespace App\Services\Upload;

/**
 * O `mime_type` do pré-flight é o que o cliente DECLARA — os bytes vão do
 * browser direto pro MinIO, então ninguém confere o conteúdo até o ffmpeg
 * rodar, horas depois. Isto lê o cabeçalho do container e recusa na hora.
 */
final class VideoSignatureService
{
    public const int HEADER_BYTES = 12;

    private const string ISO_BMFF_MARKER = 'ftyp';

    private const string MATROSKA_MAGIC = "\x1A\x45\xDF\xA3";

    public function looksLikeVideo(string $header): bool
    {
        if ($this->isIsoBmff($header)) {
            return true;
        }

        return $this->isMatroska($header);
    }

    /**
     * MP4 e MOV: box de tamanho (4 bytes) seguido do tipo `ftyp`.
     */
    private function isIsoBmff(string $header): bool
    {
        return mb_strlen($header, '8bit') >= 8
            && mb_substr($header, 4, 4, '8bit') === self::ISO_BMFF_MARKER;
    }

    /**
     * WebM e MKV: EBML.
     */
    private function isMatroska(string $header): bool
    {
        return str_starts_with($header, self::MATROSKA_MAGIC);
    }
}
