<?php

namespace App\Http\Controllers\Api\V1\Lead;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Http\Requests\Lead\StoreLeadRequest;
use App\Http\Requests\Lead\UpdateLeadRequest;
use App\Http\Resources\Lead\LeadResource;
use App\Services\Lead\LeadService;
use Illuminate\Http\JsonResponse;

class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leadService) {}

    public function index(): JsonResponse
    {
        // Global scope automatically filters to the auth user's agency.
        $leads = Lead::with('assignedAgent')->latest()->paginate(20);
        
        return LeadResource::collection($leads)->response();
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $lead = $this->leadService->createLead($request->validated(), auth()->id());

        return (new LeadResource($lead))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Lead $lead): JsonResponse
    {
        return (new LeadResource($lead->load(['activities', 'documents'])))->response();
    }

    public function update(UpdateLeadRequest $request, Lead $lead): JsonResponse
    {
        $this->leadService->updateLead($lead, $request->validated(), auth()->id());

        return (new LeadResource($lead))->response();
    }
}