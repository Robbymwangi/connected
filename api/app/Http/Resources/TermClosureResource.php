<?php

namespace App\Http\Resources;

use App\Models\TermClosure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TermClosure */
class TermClosureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
            'year' => $this->year,
            'term' => $this->term,
            'closed_by' => $this->closed_by,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'version' => $this->version,
        ];
    }
}
