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

    public function badgeClass(): string
    {
        return match ($this) {
            self::AwaitingUpload => 'bg-slate-500/15 text-slate-300',
            self::Uploaded => 'bg-sky-500/15 text-sky-300',
            self::Packaging => 'bg-amber-500/15 text-amber-300',
            self::Ready => 'bg-emerald-500/15 text-emerald-300',
            self::Failed, self::Rejected => 'bg-red-500/15 text-red-300',
        };
    }
}
