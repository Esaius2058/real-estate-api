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
        $user = auth()->user();
        $page = request()->get('page', 1);

        $cacheKey = "agency_{$user->agency_id}_user_{$user->id}_leads_page_{$page}";

        $responseData = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($user) {
            $query = Lead::with('assignedAgent')->latest();

            if ($user->role === 'agent') {
                // Ensure this matches your database column (assigned_agent_id or user_id)
                $query->where('assigned_agent_id', $user->id); 
            }

            return LeadResource::collection($query->paginate(20))->response()->getData(true);
        });
        
        return response()->json($responseData);
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        // Strip and force secure ownership IDs BEFORE passing to the service
        $validated['agency_id'] = $user->agency_id;
        $validated['assigned_agent_id'] = $user->id; // Assign to the creator by default

        $lead = $this->leadService->createLead($validated, $user->id);

        Cache::forget("agency_{$user->agency_id}_user_{$user->id}_leads_page_1");
        Cache::forget("agency_{$user->agency_id}_leads_page_1"); 

        return response()->json(['data' => $lead], 201);
    }

    public function show(Lead $lead): JsonResponse
    {
        // FIX: Verify the user is allowed to view this specific lead
        $this->authorize('view', $lead);

        $agencyId = auth()->user()->agency_id;
        $cacheKey = "agency_{$agencyId}_lead_{$lead->id}";

        $cachedLeadData = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($lead) {
            $lead->load(['activities', 'documents']);
            return (new LeadResource($lead))->response()->getData(true);
        });

        return response()->json($cachedLeadData);
    }

    public function update(UpdateLeadRequest $request, Lead $lead): JsonResponse
    {
        // FIX: Verify the user is allowed to update this specific lead
        $this->authorize('update', $lead);

        $this->leadService->updateLead($lead, $request->validated(), auth()->id());

        $agencyId = auth()->user()->agency_id;

        Cache::forget("agency_{$agencyId}_lead_{$lead->id}");
        Cache::forget("agency_{$agencyId}_leads_page_1");

        return (new LeadResource($lead->fresh(['activities', 'documents'])))->response();
    }
}