<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SafeRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'origin.lat' => ['required','numeric','between:-90,90'],
            'origin.lng' => ['required','numeric','between:-180,180'],
            'destination.lat' => ['required','numeric','between:-90,90'],
            'destination.lng' => ['required','numeric','between:-180,180'],
            'routing_profile' => ['nullable','string','in:traffic,driving,walking,cycling'],
            // vehicle_type is the primary client knob (Car/Motor/Truck/Walking).
            // We still accept routing_profile for compatibility, but some combinations
            // are normalized in the controller/service.
            'vehicle_type' => ['required','string','in:car,motor,truck,walking'],
            'exclude' => ['nullable','array'],
            'exclude.*' => ['string','in:toll,motorway,ferry,unpaved,cash_only_tolls'],
            'avoid_motorway' => ['nullable','boolean'],
            // Client naming: in the mobile app this is exposed as avoid_tolls.
            // For Motor, we treat this as "avoid motorway" per product spec.
            'avoid_tolls' => ['nullable','boolean'],
            // When true, graph routing also avoids community-reported flooded segments.
            'toggle_community_report' => ['nullable','boolean'],
            'max_attempts' => ['nullable','integer','min:1','max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $vehicleType = $this->input('vehicle_type');
        $routingProfile = $this->input('routing_profile');
        $exclude = $this->input('exclude');

        $vehicleTypeNorm = is_string($vehicleType) ? strtolower(trim($vehicleType)) : $vehicleType;
        $routingProfileNorm = is_string($routingProfile) ? strtolower(trim($routingProfile)) : $routingProfile;

        $excludeNorm = $exclude;
        if (is_array($exclude)) {
            $excludeNorm = array_values(array_filter(array_map(static function ($v) {
                return is_string($v) ? strtolower(trim($v)) : null;
            }, $exclude), static fn ($v) => is_string($v) && $v !== ''));
        }

        $this->merge([
            'vehicle_type' => $vehicleTypeNorm,
            'routing_profile' => $routingProfileNorm,
            'exclude' => $excludeNorm,
        ]);
    }
}
