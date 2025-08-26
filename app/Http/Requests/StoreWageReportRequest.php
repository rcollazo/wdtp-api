<?php

namespace App\Http\Requests;

use App\Models\WageReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWageReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Both authenticated and anonymous submissions are allowed
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'position_category_id' => ['required', 'integer', 'exists:position_categories,id'],
            'wage_amount' => ['required', 'numeric', 'min:1', 'max:999999.99'],
            'wage_type' => ['required', 'in:hourly,weekly,biweekly,monthly,yearly,per_shift'],
            'employment_type' => ['required', 'in:full_time,part_time,contract,seasonal'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:50'],
            'hours_per_week' => ['nullable', 'integer', 'min:1', 'max:168'],
            'effective_date' => ['nullable', 'date', 'before_or_equal:today'],
            'tips_included' => ['nullable', 'boolean'],
            'unionized' => ['nullable', 'boolean'],
            'additional_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'location_id.required' => 'The location is required.',
            'location_id.exists' => 'The selected location does not exist.',
            'position_category_id.required' => 'The position category is required.',
            'position_category_id.exists' => 'The selected position category does not exist.',
            'wage_amount.required' => 'The wage amount is required.',
            'wage_amount.numeric' => 'The wage amount must be a valid number.',
            'wage_amount.min' => 'The wage amount must be at least $1.00.',
            'wage_amount.max' => 'The wage amount cannot exceed $999,999.99.',
            'wage_type.required' => 'The wage type is required.',
            'wage_type.in' => 'The wage type must be one of: hourly, weekly, biweekly, monthly, yearly, per_shift.',
            'employment_type.required' => 'The employment type is required.',
            'employment_type.in' => 'The employment type must be one of: full_time, part_time, contract, seasonal.',
            'years_experience.integer' => 'Years of experience must be a whole number.',
            'years_experience.min' => 'Years of experience cannot be negative.',
            'years_experience.max' => 'Years of experience cannot exceed 50 years.',
            'hours_per_week.integer' => 'Hours per week must be a whole number.',
            'hours_per_week.min' => 'Hours per week must be at least 1.',
            'hours_per_week.max' => 'Hours per week cannot exceed 168 (24 hours × 7 days).',
            'effective_date.date' => 'The effective date must be a valid date.',
            'effective_date.before_or_equal' => 'The effective date cannot be in the future.',
            'tips_included.boolean' => 'Tips included must be true or false.',
            'unionized.boolean' => 'Unionized must be true or false.',
            'additional_notes.max' => 'Additional notes cannot exceed 1000 characters.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Check for duplicate submission (only for authenticated users)
            if (auth()->check()) {
                $this->checkForDuplicate($validator);
            }

            // Validate wage amount bounds after conversion to cents
            $this->validateWageBounds($validator);

            // Validate position category belongs to appropriate industry (optional enhancement)
            $this->validatePositionCategoryContext($validator);
        });
    }

    /**
     * Check for duplicate submissions within 30 days for authenticated users
     */
    protected function checkForDuplicate(Validator $validator): void
    {
        $userId = auth()->id();
        $locationId = $this->input('location_id');
        $positionCategoryId = $this->input('position_category_id');

        if (! $userId || ! $locationId || ! $positionCategoryId) {
            return; // Basic validation will catch missing required fields
        }

        $thirtyDaysAgo = now()->subDays(30);

        $existingReport = WageReport::where('user_id', $userId)
            ->where('location_id', $locationId)
            ->where('position_category_id', $positionCategoryId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->exists();

        if ($existingReport) {
            $validator->errors()->add(
                'duplicate',
                'You have already submitted a wage report for this position at this location within the last 30 days. Please wait before submitting another report.'
            );
        }
    }

    /**
     * Validate wage amount bounds after conversion to cents
     */
    protected function validateWageBounds(Validator $validator): void
    {
        $wageAmount = $this->input('wage_amount');
        $wageType = $this->input('wage_type');
        $hoursPerWeek = $this->input('hours_per_week');

        if (! $wageAmount || ! $wageType) {
            return; // Basic validation will catch these
        }

        try {
            $amountCents = (int) ($wageAmount * 100);
            $normalizedHourlyCents = WageReport::normalizeToHourly(
                $amountCents,
                $wageType,
                $hoursPerWeek
            );

            // Additional bounds check beyond model constants
            if ($normalizedHourlyCents < 200) { // $2.00/hour
                $validator->errors()->add(
                    'wage_amount',
                    'The wage amount results in an hourly rate below $2.00, which seems unrealistic.'
                );
            }

            if ($normalizedHourlyCents > 20000) { // $200.00/hour
                $validator->errors()->add(
                    'wage_amount',
                    'The wage amount results in an hourly rate above $200.00, which seems unrealistic.'
                );
            }

        } catch (\Exception $e) {
            $validator->errors()->add(
                'wage_amount',
                'The wage amount and type combination results in an invalid hourly rate.'
            );
        }
    }

    /**
     * Validate position category context (optional enhancement)
     */
    protected function validatePositionCategoryContext(Validator $validator): void
    {
        // For now, just verify the position category exists and is active
        // Future enhancement: validate it belongs to organization's industry
        $positionCategoryId = $this->input('position_category_id');

        if (! $positionCategoryId) {
            return;
        }

        $positionCategory = \App\Models\PositionCategory::find($positionCategoryId);

        if ($positionCategory && $positionCategory->status !== 'active') {
            $validator->errors()->add(
                'position_category_id',
                'The selected position category is not currently active.'
            );
        }
    }

    /**
     * Get the formatted data for creating a wage report
     */
    public function getWageReportData(): array
    {
        // Get the position category name for job_title
        $positionCategory = \App\Models\PositionCategory::find($this->validated()['position_category_id']);

        $validatedData = $this->validated();

        $data = [
            'location_id' => $validatedData['location_id'],
            'position_category_id' => $validatedData['position_category_id'],
            'job_title' => $positionCategory?->name ?? 'Unknown Position',
            'employment_type' => $validatedData['employment_type'],
            'wage_period' => $validatedData['wage_type'],
            'currency' => 'USD',
            'amount_cents' => (int) ($validatedData['wage_amount'] * 100),
            'hours_per_week' => $validatedData['hours_per_week'] ?? WageReport::DEFAULT_HOURS_PER_WEEK,
            'effective_date' => $validatedData['effective_date'] ?? now()->toDateString(),
            'tips_included' => $validatedData['tips_included'] ?? false,
            'unionized' => $validatedData['unionized'] ?? null,
            'source' => 'user',
            'notes' => $validatedData['additional_notes'] ?? null,
        ];

        // Set user_id only if authenticated
        if (auth()->check()) {
            $data['user_id'] = auth()->id();
        }

        return array_filter($data, fn ($value) => $value !== null);
    }
}
