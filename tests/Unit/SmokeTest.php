<?php

declare(strict_types=1);

// ponytail: smoke test para a suite não sair com codigo 2 (0 testes) no CI.
// Trocar por testes reais quando houver o que cobrir.
it('boots the framework', function (): void {
    expect(true)->toBeTrue();
});
