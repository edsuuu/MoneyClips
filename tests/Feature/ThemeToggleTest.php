<?php

declare(strict_types=1);

use App\Models\User;

it('deixa o tema a cargo do cliente e oferece o toggle no menu do usuário', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('videos.index'));

    $response->assertOk();

    $html = (string) $response->getContent();

    preg_match('/<html\b[^>]*>/', $html, $htmlTag);

    // O tema vem do localStorage: o <html> não pode nascer com a classe fixa,
    // senão o claro nunca aparece.
    expect($htmlTag[0] ?? '')->not->toContain('dark');
    expect($html)->toContain('localStorage.theme');

    // Um toggle na sidebar e outro na navbar mobile.
    expect(mb_substr_count((string) $response->getContent(), 'data-test="theme-toggle"'))->toBe(2);
});
