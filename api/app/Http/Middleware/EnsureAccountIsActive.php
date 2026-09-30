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
   caller which. */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->deactivated_at !== null) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
