<?php

use Illuminate\Support\Facades\Route;

// The API has no HTML. A browser opened on the root gets pointed the right way;
// the health endpoint at /up is registered in bootstrap/app.php.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api'),
    'health' => url('/up'),
]));
