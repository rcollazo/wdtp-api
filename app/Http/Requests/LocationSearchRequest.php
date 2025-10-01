<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LocationSearchRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:2'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:50'],
            'include_osm' => ['nullable', 'boolean'],
            'min_wage_reports' => ['nullable', 'integer', 'min:0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
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
            'q.required' => 'Search query is required',
            'q.min' => 'Search query must be at least 2 characters',
            'lat.required' => 'Latitude is required',
            'lat.numeric' => 'Latitude must be a numeric value',
            'lat.between' => 'Latitude must be between -90 and 90',
            'lng.required' => 'Longitude is required',
            'lng.numeric' => 'Longitude must be a numeric value',
            'lng.between' => 'Longitude must be between -180 and 180',
            'radius_km.numeric' => 'The radius must be a numeric value',
            'radius_km.min' => 'The radius must be at least 0.1 kilometers',
            'radius_km.max' => 'The radius cannot exceed 50 kilometers',
            'include_osm.boolean' => 'The include_osm parameter must be true or false',
            'min_wage_reports.integer' => 'Minimum wage reports must be an integer',
            'min_wage_reports.min' => 'Minimum wage reports cannot be negative',
            'per_page.integer' => 'Results per page must be an integer',
            'per_page.min' => 'Results per page must be at least 1',
            'per_page.max' => 'Cannot request more than 500 results per page',
        ];
    }
}
