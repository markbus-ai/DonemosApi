<?php

namespace App\Http\Resources;

use App\Models\Autoexclusion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recorded self-exclusion. Intentionally carries no patient identity.
 *
 * @mixin Autoexclusion
 */
class AutoexclusionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'donacion_id' => $this->donacion_id,
            'motivo' => $this->motivo,
            'created_by' => $this->created_by,
        ];
    }
}
