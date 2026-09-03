<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function store(PurchaseOrder $purchaseOrder, StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = DB::transaction(function () use ($purchaseOrder, $request): Invoice {
            $invoice = $purchaseOrder->invoices()->create([
                'reference' => $request->validated('reference'),
                'supplier_id' => $request->validated('supplier_id'),
                'invoice_date' => $request->validated('invoice_date'),
                'currency' => $request->validated('currency') ?? 'XOF',
                'notes' => $request->validated('notes'),
                'status' => 'submitted',
            ]);

            $invoice->lines()->createMany(
                collect($request->validated('lines'))->map(fn (array $line): array => [
                    'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                    'article_code' => $line['article_code'],
                    'description' => $line['description'],
                    'qty_invoiced' => $line['qty_invoiced'],
                    'unit_price' => $line['unit_price'],
                ])->all()
            );

            return $invoice;
        });

        return InvoiceResource::make($this->loadState($invoice))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($this->loadState($invoice));
    }

    private function loadState(Invoice $invoice): Invoice
    {
        return $invoice->load([
            'lines.currentMatchDecision.consumptions',
            'lines.paymentAuthorization',
        ]);
    }
}
