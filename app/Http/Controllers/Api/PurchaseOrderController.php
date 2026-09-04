<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $purchaseOrders = PurchaseOrder::query()
            ->with(['supplier:id,code,name', 'project:id,code,name'])
            ->withCount('lines')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return PurchaseOrderResource::collection($purchaseOrders);
    }

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
