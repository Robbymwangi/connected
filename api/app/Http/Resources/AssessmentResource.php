<?php

namespace App\Http\Resources;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Assessment */
class AssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
            'subject' => $this->whenLoaded('subject', fn () => [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
            ]),
            'name' => $this->name,
            'term' => $this->term,
            'year' => $this->year,
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
        ];
    }
}
