<?php

namespace App\Http\Controllers;

use App\Http\Resources\TermClosureResource;
use App\Models\SchoolClass;
use App\Models\TermClosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/* POST /api/term-closures: closing one stream's term, the trigger for its report cards
   (docs/spec/workflow.md, Term close; ADR 0012). Online only, like unlock, and outside the mutation
   path: it needs a check across the term's assessments and has server-side effects. 201 with the
   closure when this request closed it, 200 with the existing one when the term was already closed.
   meta says how many pending comments were created and how many students were skipped because they
   already have a live comment for the term. */
class TermClosuresController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'class_id' => ['required', 'uuid'],
            'year' => ['required', 'integer', 'between:1,9999'],
            'term' => ['required', 'integer', 'between:1,3'],
        ]);

        $schoolClass = SchoolClass::find($attributes['class_id'])
            ?? throw ValidationException::withMessages(['class_id' => 'The selected class does not exist.']);

        $outcome = TermClosure::close($request->user(), $schoolClass, (int) $attributes['year'], (int) $attributes['term']);

        return TermClosureResource::make($outcome['closure'])
            ->additional(['meta' => ['comments' => $outcome['comments'], 'skipped' => $outcome['skipped']]])
            ->response()
            ->setStatusCode($outcome['created'] ? 201 : 200);
    }
}
