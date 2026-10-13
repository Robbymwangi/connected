<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* docs/spec/data-model.md, users: a deactivated account "just can't
   authenticate or act." LoginController already refuses one at the
   password check, but that only stops a new token being issued; a token
   issued before deactivation keeps working otherwise, since Sanctum tokens
   carry no expiration by default (config/sanctum.php). This is the other
   half, on every request behind auth:sanctum, not only at login.

   Raises the same AuthenticationException an invalid or missing token
   would, so the response is indistinguishable from one: a bad token and a
   deactivated account both just stop working, with nothing to tell a
   caller which. An admin account is refused here too (ADR 0014,
   decision 1): it is never issued a token, and this is the backstop if
   one ever existed. */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ($user->deactivated_at !== null || ! $user->isTeacher())) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
