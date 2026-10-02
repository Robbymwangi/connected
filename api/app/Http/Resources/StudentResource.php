<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/* enrolments is included only when the controller eager-loaded it (asking
   for a roster filtered by class and/or year, build plan 2.1 #48); a plain
   student list carries no class fact at all, since which class a student
   sits in is a fact of a year, not of the student
   (docs/spec/data-model.md, enrolments). */
/** @mixin Student */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'gender' => $this->gender,
            'dob' => $this->dob?->toDateString(),
            'enrolments' => $this->whenLoaded(
                'enrolments',
                fn () => $this->enrolments->map(fn ($enrolment) => [
                    'class_id' => $enrolment->class_id,
                    'year' => $enrolment->year,
                ]),
            ),
        ];
    }
}
