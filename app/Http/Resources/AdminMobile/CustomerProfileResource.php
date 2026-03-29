<?php

namespace App\Http\Resources\AdminMobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Customer */
class CustomerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_e164' => $this->phone_e164,
            'mobile_user_id' => $this->mobile_user_id,
            'avatar_url' => $this->avatar_url,
            'display_contact' => $this->display_contact,
            'preferred_channel' => $this->preferred_channel,
            'preferred_pickup' => $this->preferred_pickup,
            'preferred_destination' => $this->preferred_destination,
            'preferred_departure_time' => $this->preferred_departure_time?->toIso8601String(),
            'status' => $this->status,
            'total_bookings' => (int) ($this->total_bookings ?? 0),
            'total_spent' => $this->total_spent !== null ? (float) $this->total_spent : null,
            'last_interaction_at' => $this->last_interaction_at?->toIso8601String(),
        ];
    }
}
