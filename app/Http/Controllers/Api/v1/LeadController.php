<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Http\Requests\Lead\StoreLeadRequest;
use App\Http\Requests\Lead\UpdateLeadRequest;
use App\Http\Resources\Lead\LeadResource;
use App\Services\Lead\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leadService) {}

    public function index(): JsonResponse
    {
        $agencyId = auth()->user()->agency_id;
        $page = request()->get('page', 1);

        $cacheKey = "agency_{$agencyId}_leads_page_{$page}";

        // Cache the Kanban board / leads list for 30 minutes
        $leads = Cache::remember($cacheKey, now()->addMinutes(30), function () {
            // Global scope automatically filters to the auth user's agency.
            return Lead::with('assignedAgent')->latest()->paginate(20);
        });
        
        return LeadResource::collection($leads)->response();
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $lead = $this->leadService->createLead($request->validated(), auth()->id());

        // CRITICAL: Clear the first page cache so the new lead appears on the Kanban board instantly
        $agencyId = auth()->user()->agency_id;
        Cache::forget("agency_{$agencyId}_leads_page_1");

        return (new LeadResource($lead))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Lead $lead): JsonResponse
    {
        $agencyId = auth()->user()->agency_id;
        $cacheKey = "agency_{$agencyId}_lead_{$lead->id}";

        // Cache the individual lead and its heavy relationships (activities, documents)
        $cachedLead = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($lead) {
            return $lead->load(['activities', 'documents']);
        });

        return (new LeadResource($cachedLead))->response();
    }

    public function update(UpdateLeadRequest $request, Lead $lead): JsonResponse
    {
        $this->leadService->updateLead($lead, $request->validated(), auth()->id());

        $agencyId = auth()->user()->agency_id;

        // CRITICAL: Invalidate both the specific lead's cache AND the main Kanban board cache
        Cache::forget("agency_{$agencyId}_lead_{$lead->id}");
        Cache::forget("agency_{$agencyId}_leads_page_1");

        // Return fresh data to the frontend to update the UI
        return (new LeadResource($lead->fresh(['activities', 'documents'])))->response();
    }
}