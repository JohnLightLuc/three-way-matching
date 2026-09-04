<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ReviewMatchDecisionRequest extends FormRequest
{
    /** L'accès est déjà filtré par le middleware can:review-decisions sur la route. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,reject'],
            // Optionnel : quantité imposée par le réviseur (approve). Absente => tout le rapprochable.
            'authorized_qty' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3', 'prohibited_if:action,reject'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
