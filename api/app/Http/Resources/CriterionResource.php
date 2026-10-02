<?php

namespace App\Http\Resources;

use App\Models\Criterion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Criterion */
class CriterionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'max_score' => $this->max_score,
        ];
    }
}
