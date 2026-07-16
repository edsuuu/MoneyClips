<?php

declare(strict_types=1);

namespace App\Services\TikTok;

use RuntimeException;

/**
 * O uploader respondeu 401 (LoginFailedError): os cookies enviados não
 * autenticam mais. O poster marca a conta como session_status=invalid e
 * curto-circuita os próximos posts até o operador renovar em /contas.
 */
final class SessionInvalidException extends RuntimeException {}
