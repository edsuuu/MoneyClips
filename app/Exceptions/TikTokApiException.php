<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Recusa da API oficial do TikTok (OAuth ou Content Posting) já com a
 * mensagem legível que vai pro `social_posts.error` ou pro toast de /contas.
 * Nunca carrega token.
 */
final class TikTokApiException extends RuntimeException {}
