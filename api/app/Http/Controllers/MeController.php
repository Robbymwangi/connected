<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/* GET /api/me (docs/build-plan.md 1.5, #43): docs/spec/access-model.md,
   Roles: "GET /me returns which subjects, if any, an account moderates, and
   whether it holds admin, rather than a single role string." Moderation is
   a list of subject ids, not a boolean, because it is scoped per subject
   (Moderation, in the same file); flattening it to a single canModerate
   boolean is 1.7's frontend concern, against a specific assessment's
   subject, not this endpoint's. */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'moderated_subject_ids' => $user->subjectModerations()->pluck('subject_id'),
        ]);
    }
}
