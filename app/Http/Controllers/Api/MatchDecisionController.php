<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReviewMatchDecisionRequest;
use App\Http\Resources\MatchDecisionResource;
use App\Models\InvoiceLine;
use App\Models\MatchDecision;
use App\Services\ThreeWayMatchingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MatchDecisionController extends Controller
{
    public function __construct(private readonly ThreeWayMatchingService $matching) {}

    /**
     * File de revue : décisions COURANTES encore à trancher — status needs_review
     * ET actor_type=system (une décision rejetée par un humain reste needs_review
     * mais actor_type=user, donc sort de la file).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->query('status', 'needs_review');

        $decisions = MatchDecision::query()
            ->current()
            ->where('status', $status)
            ->when($status === 'needs_review', fn ($q) => $q->where('actor_type', 'system'))
            ->with(['consumptions', 'invoiceLine:id,invoice_id,article_code,qty_invoiced,unit_price', 'invoiceLine.invoice:id,reference'])
            ->orderByDesc('id')
            ->get();

        return MatchDecisionResource::collection($decisions);
    }

    /** Historique complet des décisions d'une ligne de facture (chaîne supersedes), la plus récente d'abord. */
    public function history(InvoiceLine $invoiceLine): AnonymousResourceCollection
    {
        $decisions = $invoiceLine->matchDecisions()
            ->with(['consumptions', 'actorUser:id,name', 'supersededBy:id,supersedes_id'])
            ->orderByDesc('id')
            ->get();

        return MatchDecisionResource::collection($decisions);
    }

    public function review(MatchDecision $matchDecision, ReviewMatchDecisionRequest $request): MatchDecisionResource
    {
        $decision = $this->matching->review(
            $matchDecision,
            $request->validated('action'),
            $request->user(),
            $request->validated('authorized_qty'),
        );

        return MatchDecisionResource::make($decision->load('consumptions'));
    }
}
