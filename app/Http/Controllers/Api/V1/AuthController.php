<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    use ApiResponses;

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'avatar' => null,
            'currency' => 'EUR',
        ]);

        $user->sendEmailVerificationNotification();

        $token = $user->createToken('mobile')->plainTextToken;

        return $this->created([
            'token' => $token,
            'user' => new UserResource($user->fresh()),
        ], 'Registered. Please verify your email.');
    }

    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse|HttpResponse
    {
        $user = User::query()->findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            if (! $request->wantsJson()) {
                return response()->view('auth.verify-email-failed', ['reason' => 'invalid'], Response::HTTP_FORBIDDEN);
            }

            return $this->error('Lien de vérification invalide.', Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasVerifiedEmail()) {
            if (! $user->markEmailAsVerified()) {
                if (! $request->wantsJson()) {
                    return response()->view('auth.verify-email-failed', ['reason' => 'server'], Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                return $this->error('La vérification a échoué.', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            event(new Verified($user));
        }

        if (! $request->wantsJson()) {
            return response()->view('auth.verify-email-success', [
                'user' => $user->fresh(),
            ]);
        }

        return $this->success([
            'verified' => true,
            'user' => new UserResource($user->fresh()),
        ], 'E-mail vérifié.');
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->success(['already_verified' => true], 'E-mail déjà vérifié.');
        }

        $user->sendEmailVerificationNotification();

        return $this->success(null, 'E-mail de vérification envoyé.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return $this->error('Invalid credentials', Response::HTTP_UNAUTHORIZED);
        }

        $user->refresh();

        $token = $user->createToken('mobile')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Logged in');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return $this->success(null, 'Logged out');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->refresh();

        return $this->success(new UserResource($user));
    }

    public function updateMe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'max:8'],
            'avatar' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }

        if (isset($validated['currency'])) {
            $user->currency = $validated['currency'];
        }

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $path = 'avatars/'.Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
            Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));
            $user->avatar = Storage::disk('public')->url($path);
        }

        $user->save();

        return $this->success(new UserResource($user->fresh()), 'Profile updated');
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return $this->error('Current password is incorrect', Response::HTTP_UNPROCESSABLE_ENTITY, [
                'current_password' => ['Invalid'],
            ]);
        }

        $user->password = $validated['password'];
        $user->save();

        return $this->success(null, 'Password updated');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        Log::info('driply.password_reset_request', [
            'channel' => 'api_v1',
            'status' => $status,
            'mailer' => config('mail.default'),
        ]);

        if ($status !== Password::RESET_LINK_SENT) {
            return $this->error(__($status), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->success(null, __($status));
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
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
            return $this->error(__($status), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->success(null, __($status));
    }
}
