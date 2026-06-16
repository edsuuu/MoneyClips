<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class HomeController extends Controller
{
    public function __invoke(): RedirectResponse|View
    {
        if (Auth::check()) {
            return to_route('downloads.index');
        }

        return view('auth.login');
    }
}
