<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/* POST /api/login (docs/build-plan.md 1.5, #43): the one place a token is
   issued. Runs before any account is known, so the lookup by email must
   bypass InstitutionScope explicitly (ResolveInstitution's own comment:
   "routes that must read across institutions before an account exists say
   so explicitly with withoutGlobalScopes()") rather than failing closed on
   an institution nothing has resolved yet.

   docs/spec/access-model.md, Tokens: "the school is baked into the
   credential", so the request carries only email and password, never an
   institution. A wrong password and a deactivated account produce the same
   rejection: telling them apart would confirm to an attacker that the email
   belongs to a real, currently-active account. */
class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::withoutGlobalScopes()->where('email', $credentials['email'])->first();

        if (! $user || $user->deactivated_at !== null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken('device', ['sync'])->plainTextToken,
        ]);
    }
}
