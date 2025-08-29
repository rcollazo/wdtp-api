<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LocationIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Public endpoint
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'near' => ['nullable', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:50'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'near.regex' => 'The near parameter must be in the format "latitude,longitude" (e.g., "40.7128,-74.0060")',
            'radius_km.numeric' => 'The radius must be a numeric value',
            'radius_km.min' => 'The radius must be at least 0.1 kilometers',
            'radius_km.max' => 'The radius cannot exceed 50 kilometers',
            'organization_id.exists' => 'The specified organization does not exist',
            'per_page.max' => 'Cannot request more than 100 results per page',
        ];
    }
}
