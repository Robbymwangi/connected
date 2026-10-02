<?php

namespace App\Http\Controllers;

use App\Http\Resources\SchoolClassResource;
use App\Models\SchoolClass;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/* GET /api/classes (build plan 2.1, #48). Unrestricted within the
   institution for any authenticated user (docs/spec/access-model.md, The
   principle); InstitutionScope does the actual filtering, not a condition
   here. */
class ClassesController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        $classes = SchoolClass::with(['classTeacher', 'classSubjects.subject'])->get();

        return SchoolClassResource::collection($classes);
    }
}
