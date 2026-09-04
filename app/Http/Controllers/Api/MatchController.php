<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\ThreeWayMatchingService;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function __construct(private readonly ThreeWayMatchingService $matching) {}

    /** Déclenche le rapprochement 3 voies de toutes les lignes de la facture. */
    public function store(Invoice $invoice, Request $request): InvoiceResource
    {
        $this->matching->matchInvoice($invoice, $request->user());

        return InvoiceResource::make(
            $invoice->fresh([
                'lines.currentMatchDecision.consumptions',
                'lines.paymentAuthorization',
            ])
        );
    }
}
