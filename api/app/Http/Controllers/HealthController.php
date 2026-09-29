<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/* GET /api/health (docs/build-plan.md 1.6, #44): the probe the frontend's
   connectivity hook polls to tell "the browser has a network" from "the API
   is reachable" (navigator.onLine alone is true behind a captive portal).
   It answers before a device has a token or any data, so it does no work
   that could fail for another reason: no authentication, no database, no
   institution. no-store keeps a browser, a proxy, or the service worker
   from ever answering it from a cache, which would report a reachable API
   that is not. Laravel's own /up (bootstrap/app.php) is the container
   health check and stays separate. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
