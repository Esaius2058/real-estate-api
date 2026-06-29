<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
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
                // Ensure this matches your database column (assigned_agent_id or agent_id)
                $query->where('agent_id', $user->id);
            }

            return LeadResource::collection($query->paginate(20))->response()->getData(true);
        });
        
        return response()->json($responseData);
    }

    public function store(Request $request): JsonResponse
    {
        // 1. Validate incoming data
        $validated = $request->validate([
            'property_id'     => 'required|exists:properties,id',
            'contact_name'    => 'required|string|max:255',
            'contact_email'   => 'required|email|max:255',
            'contact_phone'   => 'required|string|max:50',
            'estimated_value' => 'nullable|numeric',
            'notes'           => 'nullable|string', // Will be moved to lead_requirements
        ]);

        // 2. Lookup the property
        $property = \App\Models\Property::findOrFail($validated['property_id']);

        // 3. Prepare data mapping to your exact schema
        $leadData = [
            'property_id'  => $property->id,
            'agency_id'    => $property->agency_id,
            'agent_id'     => $property->user_id,
            'name'         => $validated['contact_name'],
            'email'        => $validated['contact_email'],
            'phone'        => $validated['contact_phone'],
            'value'        => $validated['estimated_value'],
            'kanban_stage' => 'new',
            // Move notes into the JSON requirements column
            'lead_requirements' => [
                'notes' => $validated['notes'] ?? null,
                'source' => 'public_inquiry'
            ],
        ];

        // 4. Create the lead
        // Note: If you don't use a service, use \App\Models\Lead::create($leadData)
        $lead = \App\Models\Lead::create($leadData);

        // 5. Invalidate caches for the listing agent
        Cache::forget("agency_{$property->agency_id}_user_{$property->user_id}_leads_page_1");
        Cache::forget("agency_{$property->agency_id}_leads_page_1"); 

        return response()->json([
            'data' => $lead, 
            'message' => 'Lead successfully routed.'
        ], 201);
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