<?php

namespace App\Http\Requests\Consultation;

use Illuminate\Foundation\Http\FormRequest;

final class BookConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'brief' => is_string($this->brief) ? trim($this->brief) : $this->brief,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\x00-\x1f\x7f]/'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'brief' => ['nullable', 'string', 'max:1000'],
            'start' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00(?:Z|[+-]\d{2}:\d{2})$/'],
            'idempotency_key' => ['required', 'uuid'], 'consent' => ['required', 'accepted'],
            'website' => ['nullable', 'string', 'max:0'],
            'workspace_id' => ['prohibited'], 'calendar_id' => ['prohibited'], 'duration_minutes' => ['prohibited'],
        ];
    }
}
