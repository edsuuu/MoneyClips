<?php

declare(strict_types=1);

namespace App\Enums;

enum PermissionEnum: string
{
    case ObservabilityView = 'observability.view';
    case LogsView = 'logs.view';
}
