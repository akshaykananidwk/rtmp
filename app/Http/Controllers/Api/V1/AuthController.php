<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['nullable', 'string', 'max:60']]);
        $user = User::withoutGlobalScopes()->where('email', strtolower($data['email']))->first();

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            $audit->failure('api.login_failed', null, ['email' => $data['email']]);

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }
        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor enabled accounts must create API tokens from the dashboard profile page.'], 403);
        }

        $abilities = $user->isOperator() ? ['read', 'control'] : ['read'];
        if ($user->isAdmin()) {
            $abilities[] = 'manage';
        }
        $token = $user->createToken($data['device_name'] ?? 'api', $abilities, now()->addDays(30));
        $audit->log('api.login', $user, [], 'success', $user->id);

        return response()->json(['token' => $token->plainTextToken, 'abilities' => $abilities, 'expires_at' => $token->accessToken->expires_at]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'roles' => $u->roleNames(), 'tenant_id' => $u->tenant_id]);
    }
}
