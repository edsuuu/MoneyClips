<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

final class RequirePasswordUnlessGoogleAuthenticated extends RequirePassword
{
    /**
     * Google OAuth already proves control of the authenticated account.
     */
    protected function shouldConfirmPassword($request, $passwordTimeoutSeconds = null): bool
    {
        if ($request instanceof Request && $request->session()->get('auth.authenticated_via_google') === true) {
            return false;
        }

        return parent::shouldConfirmPassword($request, $passwordTimeoutSeconds);
    }
}
