<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDeliveryNoteRequest;
use App\Http\Resources\DeliveryNoteResource;
use App\Models\DeliveryNote;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DeliveryNoteController extends Controller
{
    public function store(PurchaseOrder $purchaseOrder, StoreDeliveryNoteRequest $request): JsonResponse
    {
        $note = DB::transaction(function () use ($purchaseOrder, $request): DeliveryNote {
            $note = $purchaseOrder->deliveryNotes()->create([
                'reference' => $request->validated('reference'),
                'received_at' => $request->validated('received_at'),
                'notes' => $request->validated('notes'),
            ]);

            $note->lines()->createMany(
                collect($request->validated('lines'))->map(fn (array $line): array => [
                    'purchase_order_line_id' => $line['purchase_order_line_id'],
                    'qty_received' => $line['qty_received'],
                ])->all()
            );

            return $note;
        });

        return DeliveryNoteResource::make($note->load('lines'))
            ->response()
            ->setStatusCode(201);
    }
}
