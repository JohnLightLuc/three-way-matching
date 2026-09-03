<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $purchaseOrder = DB::transaction(function () use ($request): PurchaseOrder {
            $po = PurchaseOrder::create([
                'reference' => $request->validated('reference'),
                'supplier_id' => $request->validated('supplier_id'),
                'project_id' => $request->validated('project_id'),
                'currency' => $request->validated('currency') ?? 'XOF',
                'notes' => $request->validated('notes'),
                'status' => 'open',
            ]);

            $po->lines()->createMany($request->lines());

            return $po;
        });

        return PurchaseOrderResource::make(
            $purchaseOrder->load(['supplier', 'project', 'lines'])
        )->response()->setStatusCode(201);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make(
            $purchaseOrder->load(['supplier', 'project', 'lines', 'deliveryNotes', 'invoices'])
        );
    }
}
