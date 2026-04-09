<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordResetWebController extends Controller
{
    /** Formulaire web : lien e-mail Laravel (route nommée password.reset). */
    public function show(Request $request, string $token): View
    {
        $email = (string) $request->query('email', '');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return view('auth.reset-password-invalid');
        }

        return view('auth.reset-password-form', [
            'token' => $token,
            'email' => $email,
        ]);
    }

    public function submit(ResetPasswordRequest $request): RedirectResponse
    {
        $payload = $request->validated();

        $status = Password::reset(
            $payload,
            function (User $user, string $password): void {
                $user->password = $password;
                $user->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return redirect()
                ->route('password.reset', [
                    'token' => $payload['token'],
                    'email' => $payload['email'],
                ])
                ->withErrors(['email' => __($status)]);
        }

        return redirect()->route('password.reset.success');
    }

    public function success(): View
    {
        return view('auth.reset-password-success');
    }
}
