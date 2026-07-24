<?php

declare(strict_types=1);

namespace App\Enums;

enum TranscriptionStatusEnum: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ready, self::Failed => true,
            self::Processing => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Processing => 'Processando transcrição',
            self::Ready => 'Transcrição pronta',
            self::Failed => 'Transcrição falhou',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Processing => 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
            self::Ready => 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
            self::Failed => 'bg-red-500/15 text-red-700 dark:text-red-300',
        };
    }
}
