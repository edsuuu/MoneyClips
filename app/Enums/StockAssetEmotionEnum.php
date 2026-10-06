<?php

declare(strict_types=1);

namespace App\Enums;

enum StockAssetEmotionEnum: string
{
    case Surprise = 'espanto';
    case Mockery = 'deboche';
    case Shame = 'vergonha';
    case Cringe = 'cringe';
    case Victory = 'vitoria';
    case Confusion = 'confusao';
    case Anger = 'raiva';
    case Sadness = 'tristeza';
    case Money = 'dinheiro';
    case Suspicion = 'suspeita';
    case Fear = 'medo';
    case End = 'fim';
}
