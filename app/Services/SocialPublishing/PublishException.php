<?php

declare(strict_types=1);

namespace App\Services\SocialPublishing;

use RuntimeException;

/**
 * Erro de configuração/pré-condição ao publicar (conta ausente, token expirado,
 * arquivo do corte inexistente). Capturado pelos publishers e convertido em
 * PublishResult::fail.
 */
final class PublishException extends RuntimeException {}
