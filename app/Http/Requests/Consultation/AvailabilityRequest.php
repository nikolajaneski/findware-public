<?php

namespace App\Http\Requests\Consultation;

use Illuminate\Foundation\Http\FormRequest;

final class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['date' => ['required', 'date_format:Y-m-d'], 'workspace_id' => ['prohibited'], 'calendar_id' => ['prohibited']];
    }
}
