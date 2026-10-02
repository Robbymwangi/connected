<?php

namespace App\Http\Controllers;

use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/* GET /api/subjects (build plan 2.1, #48): the subject list and, nested as
   criteria, the rubric for each one. Unrestricted within the institution for
   any authenticated user (docs/spec/access-model.md, The principle). */
class SubjectsController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        $subjects = Subject::with('criteria')->get();

        return SubjectResource::collection($subjects);
    }
}
