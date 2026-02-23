<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommunityFloodNearbyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Accept either lat/lng or Lat/Long (some clients use capitalized keys).
            'lat' => ['required_without:Lat', 'numeric', 'between:-90,90'],
            'lng' => ['required_without:Long', 'numeric', 'between:-180,180'],
            'Lat' => ['required_without:lat', 'numeric', 'between:-90,90'],
            'Long' => ['required_without:lng', 'numeric', 'between:-180,180'],

            // Optional query params
            'radius_m' => ['sometimes', 'numeric', 'min:1', 'max:5000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
