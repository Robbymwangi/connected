<?php

namespace App\Http\Resources;

use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/* Build plan 2.1, #48: read-only, unrestricted within the institution
   (docs/spec/access-model.md, The principle). class_teacher_id is attention
   only, never a capability, so it is exposed as a plain name here, not
   anything a client branches behavior on. */
/** @mixin SchoolClass */
class SchoolClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grade' => $this->grade,
            'stream' => $this->stream,
            'class_teacher' => $this->whenLoaded(
                'classTeacher',
                fn () => $this->classTeacher === null ? null : [
                    'id' => $this->classTeacher->id,
                    'name' => $this->classTeacher->name,
                ],
            ),
            'subjects' => $this->whenLoaded(
                'classSubjects',
                fn () => $this->classSubjects->map(fn ($classSubject) => [
                    'id' => $classSubject->subject->id,
                    'name' => $classSubject->subject->name,
                ]),
            ),
        ];
    }
}
