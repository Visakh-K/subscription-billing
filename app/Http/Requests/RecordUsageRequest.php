<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->input('idempotency_key') ?? $this->header('Idempotency-Key'),
            'recorded_at' => $this->input('recorded_at', now()),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'units' => ['required', 'integer', 'min:1'],
            'recorded_at' => ['nullable', 'date'],
        ];
    }
}
