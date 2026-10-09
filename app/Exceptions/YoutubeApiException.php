<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Recusa da YouTube Data API (token, cota, validação) já com a mensagem
 * legível que vai pro `social_posts.error`. Nunca carrega token nem corpo de
 * resposta do endpoint de OAuth.
 */
final class YoutubeApiException extends RuntimeException {}
