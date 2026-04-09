<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class ForgotPasswordWebController extends Controller
{
    /** Formulaire public (navigateur) : demande d’e-mail de réinitialisation. */
    public function show(Request $request): View
    {
        return view('auth.forgot-password-request', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($request->only('email'));

        Log::info('driply.password_reset_request', [
            'channel' => 'web',
            'status' => $status,
            'mailer' => config('mail.default'),
        ]);

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        return back()->withErrors([
            'email' => __($status),
        ]);
    }
}
