<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Customer */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mobile_user_id' => $this->mobile_user_id,
            'name' => $this->name,
            'display_name' => $this->name ?: $this->display_contact,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'preferred_channel' => $this->preferred_channel,
            'mobile_device_id' => $this->mobile_device_id,
            'display_contact' => $this->display_contact,
            'status' => $this->status,
            'last_interaction_at' => $this->last_interaction_at?->toIso8601String(),
        ];
    }
}
