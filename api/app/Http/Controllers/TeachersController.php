<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TeachersController extends Controller
{
    /**
     * Create an institution teacher account after authorizing administrator access.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $teacher = User::create([
            ...$attributes,
            'institution_id' => $request->user()->institution_id,
        ]);

        return $this->resourceResponse($teacher, 201);
    }

    /**
     * Update a teacher account after authorizing administrator access.
     */
    public function update(Request $request, User $teacher): JsonResponse
    {
        Gate::authorize('update', $teacher);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($teacher->id),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:12'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $teacher->update($attributes);

        return $this->resourceResponse($teacher, 200);
    }

    /**
     * Return teacher account details without credentials, using the given HTTP status.
     */
    private function resourceResponse(User $teacher, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'email' => $teacher->email,
                'is_admin' => $teacher->is_admin,
                'deactivated_at' => $teacher->deactivated_at?->toIso8601String(),
            ],
        ], $status);
    }
}
