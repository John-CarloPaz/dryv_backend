<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommunityFloodReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Accept either lat/lng or Lat/Long (some clients use capitalized keys).
        return [
            'lat' => ['required_without:Lat', 'numeric', 'between:-90,90'],
            'lng' => ['required_without:Long', 'numeric', 'between:-180,180'],
            'Lat' => ['required_without:lat', 'numeric', 'between:-90,90'],
            'Long' => ['required_without:lng', 'numeric', 'between:-180,180'],

            // Estimated flood depth (1-4)
            'estimated_depth' => ['required', 'integer', 'between:1,4'],
        ];
    }
}
