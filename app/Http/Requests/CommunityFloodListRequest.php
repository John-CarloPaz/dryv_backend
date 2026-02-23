<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommunityFloodListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Filters
            'min_risk_level' => ['sometimes', 'integer', 'min:0', 'max:3'],
        ];
    }
}
