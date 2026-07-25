<?php

declare(strict_types=1);

namespace App\Enums;

enum VideoCutStatusEnum: string
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    public function canGenerate(): bool
    {
        return match ($this) {
            self::Draft, self::Failed => true,
            self::Generating, self::Ready => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Generating => 'Gerando corte',
            self::Ready => 'Corte pronto',
            self::Failed => 'Falhou',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
            self::Generating => 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
            self::Ready => 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
            self::Failed => 'bg-red-500/15 text-red-700 dark:text-red-300',
        };
    }
}
