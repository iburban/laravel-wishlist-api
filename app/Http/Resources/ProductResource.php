<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            // decimal:2 cast keeps price a string ("19.99") to avoid float drift.
            'price' => $this->price,
            'created_at' => $this->created_at,
        ];
    }
}
