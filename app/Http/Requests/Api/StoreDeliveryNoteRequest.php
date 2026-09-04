<?php

namespace App\Http\Requests\Api;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryNoteRequest extends FormRequest
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
            'reference' => ['required', 'string', 'max:255', Rule::unique('delivery_notes', 'reference')],
            'received_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => [
                'required',
                'integer',
                // La ligne de PO doit appartenir à CE PO (invariant M2).
                Rule::exists('purchase_order_lines', 'id')->where('purchase_order_id', $po->id),
            ],
            'lines.*.qty_received' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.*.purchase_order_line_id.exists' => 'La ligne de commande ne fait pas partie de ce bon de commande.',
        ];
    }
}
