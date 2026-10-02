<?php

namespace App\Http\Resources;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/* criteria is the rubric (build plan 2.1, #48): the lines a mark for this
   subject is broken into and their maxima, matching
   frontend/src/fixtures/rubrics.ts's rubricFor() one subject at a time, done
   here for all of them in one list. */
/** @mixin Subject */
class SubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'criteria' => CriterionResource::collection($this->whenLoaded('criteria')),
        ];
    }
}
