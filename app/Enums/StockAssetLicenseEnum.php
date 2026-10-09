<?php

declare(strict_types=1);

namespace App\Enums;

enum StockAssetLicenseEnum: string
{
    case Cc0 = 'cc0';
    case PublicDomain = 'public_domain';
    case CcBy = 'cc_by';
    case CcBySa = 'cc_by_sa';
    case Pixabay = 'pixabay';
    case Pexels = 'pexels';
    case Apache2 = 'apache2';
    case Own = 'own';
    case OwnRisk = 'own_risk';
}
