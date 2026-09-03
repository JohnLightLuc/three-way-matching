<?php

namespace App\Http\Requests\Api;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var PurchaseOrder $po */
        $po = $this->route('purchaseOrder');

        return [
            'reference' => [
                'required', 'string', 'max:255',
                // Référence unique PAR fournisseur revendiqué (UNIQUE(supplier_id, reference)).
                Rule::unique('invoices', 'reference')->where('supplier_id', (int) $this->input('supplier_id')),
            ],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'invoice_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => [
                'nullable',
                'integer',
                Rule::exists('purchase_order_lines', 'id')->where('purchase_order_id', $po->id),
            ],
            'lines.*.article_code' => ['required', 'string', 'max:255'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty_invoiced' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,4'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.*.purchase_order_line_id.exists' => 'La ligne de commande ne fait pas partie de ce bon de commande.',
            'reference.unique' => 'Ce fournisseur a déjà une facture portant cette référence.',
        ];
    }
}
