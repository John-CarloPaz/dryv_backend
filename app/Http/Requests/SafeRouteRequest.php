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
            'vehicle_type' => ['required','string','in:car,motor,truck'],
            'exclude' => ['nullable','array'],
            'exclude.*' => ['string','in:toll,motorway,ferry,unpaved,cash_only_tolls'],
            'avoid_motorway' => ['nullable','boolean'],
            'max_attempts' => ['nullable','integer','min:1','max:20'],
        ];
    }
}
