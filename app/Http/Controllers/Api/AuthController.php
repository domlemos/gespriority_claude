<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Customer;
use App\Models\TokenAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Per-guard token policy: which Eloquent model backs the guard, the
     * Sanctum ability granted to its tokens, the TTL applied at creation
     * (Sanctum's global `sanctum.expiration` can't vary by guard — see
     * config/sanctum.php), and the password-reset broker to use.
     */
    private const GUARDS = [
        'web' => [
            'model' => User::class,
            'ability' => 'staff',
            'ttl_minutes' => 120,
            'broker' => 'users',
        ],
        'customer' => [
            'model' => Customer::class,
            'ability' => 'customer',
            'ttl_minutes' => 240,
            'broker' => 'customers',
        ],
    ];

    public function login(Request $request, string $guard = 'web')
    {
        $config = $this->guardConfig($guard);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $model = $config['model']::query()->where('email', $credentials['email'])->first();

        if (! $model || ! Hash::check($credentials['password'], $model->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $token = $model->createToken(
            'spa',
            [$config['ability']],
            now()->addMinutes($config['ttl_minutes'])
        );

        $this->audit($model, 'issued', $request);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
            'user' => $this->presentAuthenticatable($model),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(
            $this->presentAuthenticatable($request->user())
        );
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        $config = $this->guardConfig($this->guardNameFor($user));
        $currentToken = $user->currentAccessToken();

        $token = DB::transaction(function () use ($user, $currentToken, $config) {
            $new = $user->createToken(
                'spa',
                $currentToken->abilities,
                now()->addMinutes($config['ttl_minutes'])
            );

            $currentToken->delete();

            return $new;
        });

        $this->audit($user, 'refreshed', $request);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        $user->currentAccessToken()->delete();

        $this->audit($user, 'revoked', $request);

        return response()->json(['message' => 'Logged out.']);
    }

    public function logoutAll(Request $request)
    {
        $user = $request->user();

        $user->tokens()->delete();

        $this->audit($user, 'revoked_all', $request);

        return response()->json(['message' => 'Logged out from all devices.']);
    }

    public function forgotPassword(Request $request, string $guard = 'web')
    {
        $config = $this->guardConfig($guard);

        $request->validate(['email' => ['required', 'email']]);

        Password::broker($config['broker'])->sendResetLink(
            $request->only('email')
        );

        return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request, string $guard = 'web')
    {
        $config = $this->guardConfig($guard);

        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::broker($config['broker'])->reset(
            $credentials,
            function ($model) use ($credentials) {
                $model->forceFill([
                    'password' => Hash::make($credentials['password']),
                    'remember_token' => Str::random(60),
                ])->save();

                $model->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    private function presentAuthenticatable(User|Customer $model): UserResource|Customer
    {
        if ($model instanceof User) {
            return new UserResource($model->loadMissing('roles.permissions'));
        }

        return $model;
    }

    private function guardConfig(string $guard): array
    {
        return self::GUARDS[$guard] ?? abort(404);
    }

    private function guardNameFor(User|Customer $user): string
    {
        return $user instanceof Customer ? 'customer' : 'web';
    }

    private function audit(User|Customer $model, string $action, Request $request): void
    {
        $log = new TokenAuditLog([
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $log->tokenable()->associate($model);
        $log->save();
    }
}
