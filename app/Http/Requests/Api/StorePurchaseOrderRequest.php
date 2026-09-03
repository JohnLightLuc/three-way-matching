<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:255', Rule::unique('purchase_orders', 'reference')],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_no' => ['nullable', 'integer', 'min:1', 'distinct'],
            'lines.*.article_code' => ['required', 'string', 'max:255'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.unit' => ['required', 'string', 'max:255'],
            'lines.*.qty_ordered' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,4'],
        ];
    }

    /**
     * Lignes normalisées : line_no auto-attribué (1..n) quand il est absent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lines(): array
    {
        return collect($this->validated('lines'))
            ->values()
            ->map(fn (array $line, int $i): array => [
                'line_no' => $line['line_no'] ?? $i + 1,
                'article_code' => $line['article_code'],
                'description' => $line['description'],
                'unit' => $line['unit'],
                'qty_ordered' => $line['qty_ordered'],
                'unit_price' => $line['unit_price'],
            ])
            ->all();
    }
}
