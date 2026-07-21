<?php

declare(strict_types=1);

namespace App\Services\HLS;

enum VideoStatusEnum: string
{
    case AwaitingUpload = 'awaiting_upload';
    case Uploaded = 'uploaded';
    case Packaging = 'packaging';
    case Ready = 'ready';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /** @return list<self> */
    public static function pending(): array
    {
        return [self::AwaitingUpload, self::Uploaded, self::Packaging];
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ready, self::Failed, self::Rejected => true,
            default => false,
        };
    }

    public function isPlayable(): bool
    {
        return $this === self::Ready;
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingUpload => 'Enviando',
            self::Uploaded => 'Na fila',
            self::Packaging => 'Preparando reprodução',
            self::Ready => 'Pronto',
            self::Failed => 'Falhou',
            self::Rejected => 'Arquivo inválido',
        };
    }
}
