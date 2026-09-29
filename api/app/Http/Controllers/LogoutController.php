<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/* POST /api/logout (docs/build-plan.md 1.5, #43): revokes the token that
   authenticated this request, and only that one. A device signing out does
   not sign out every other device the same account is logged into
   elsewhere. */
class LogoutController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
