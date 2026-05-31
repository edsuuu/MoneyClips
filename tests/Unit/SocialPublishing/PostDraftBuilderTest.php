<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\Video;
use App\Services\SocialPublishing\PostDraftBuilder;

it('builds fallback metadata with part label for a cut', function () {
    $builder = new PostDraftBuilder;
    $video = new Video([
        'title' => 'Como crescer no YouTube',
    ]);
    $cut = new Cut([
        'index' => 1,
        'name' => 'PT1',
        'hashtags' => [],
    ]);

    $draft = $builder->forCut($video, $cut);

    expect($draft['part_label'])->toBe('PT1');
    expect($draft['title'])->toContain('PT1');
    expect($draft['description'])->toContain('PT1');
    expect($draft['hashtags'])->toContain('shorts', 'cortes', 'pt1');
});

it('keeps existing metadata while ensuring the part label is present', function () {
    $builder = new PostDraftBuilder;
    $video = new Video([
        'title' => 'Titulo base',
    ]);
    $cut = new Cut([
        'index' => 2,
        'name' => 'PT2',
        'title' => 'Gancho forte',
        'description' => 'Descricao pronta',
        'hashtags' => ['viral'],
    ]);

    $draft = $builder->forCut($video, $cut);

    expect($draft['title'])->toContain('Gancho forte');
    expect($draft['title'])->toContain('PT2');
    expect($draft['description'])->toContain('Descricao pronta');
    expect($draft['description'])->toContain('PT2');
    expect($draft['hashtags'])->toContain('viral', 'pt2');
});
