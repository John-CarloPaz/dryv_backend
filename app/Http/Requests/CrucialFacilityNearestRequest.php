<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrucialFacilityNearestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Accept latitude/longitude but also tolerate lat/lng and Lat/Long used by other endpoints.
        $lat = $this->input('latitude', $this->input('lat', $this->input('Lat')));
        $lng = $this->input('longitude', $this->input('lng', $this->input('Long')));

        $merge = [];
        if ($lat !== null && !$this->has('latitude')) {
            $merge['latitude'] = $lat;
        }
        if ($lng !== null && !$this->has('longitude')) {
            $merge['longitude'] = $lng;
        }

        // Normalize common variant to match seeded values.
        $type = $this->input('type');
        if (is_string($type)) {
            $t = strtolower(trim($type));
            if ($t === 'evacuation_center') {
                $t = 'evacuation center';
            }
            $merge['type'] = $t;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],

            // Optional: if provided, return only one type.
            'type' => ['sometimes', 'string', 'in:police,hospital,evacuation center'],

            // Limit is per type when requesting all types.
            'limit_per_type' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
