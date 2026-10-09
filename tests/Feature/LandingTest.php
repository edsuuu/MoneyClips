<?php

declare(strict_types=1);

it('serves the public landing and legal pages without invented content', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('name="description"', false)
        ->assertSee('og:title', false)
        ->assertDontSee('Rafael Souza')
        ->assertDontSee('Assinar')
        ->assertDontSee('Plano atual');

    $this->get('/termos-de-uso')->assertOk()->assertSee('Termos de Uso');
    $this->get('/politica-de-privacidade')->assertOk()->assertSee('Política de Privacidade');
});
