<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\Property\PropertyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use App\Services\ActivityLog\ActivityService;

class PropertyController extends Controller
{
    protected $activity;

    public function __construct(ActivityService $activity)
    {
        $this->activity = $activity;
    }

    public function index(): JsonResponse
    {
        $properties = Property::where('status', 'active')
            ->with(['images', 'agency'])
            ->latest()
            ->limit(20) // Good practice to limit public payloads
            ->get();

        // If you have a custom formatting helper, map it here
        return response()->json(['data' => $properties]);
    }

    public function agencyIndex(Request $request)
    {
        $user = auth()->user();
        $page = request()->get('page', 1);

        $cacheKey = "agency_{$user->agency_id}_user_{$user->id}_properties_page_{$page}";

        $responseData = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($user) {
            
            // FIX: Query the Property model, not Lead.
            $query = Property::with(['agent', 'images'])->latest();

            // Role-based isolation
            if ($user->role === 'agent') {
                $query->where('user_id', $user->id); 
            }

            return PropertyResource::collection($query->paginate(20))->response()->getData(true);
        });
        
        return response()->json($responseData);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        // Strip and force secure ownership IDs
        $validated['agency_id'] = $user->agency_id;
        $validated['user_id'] = $user->id; 

        $property = Property::create($validated);
        $this->activity->log(
            auth()->id(), 
            "Created new property: {$property->title} (ID: {$property->id})", 
            $user->agency_id
        );

        // Invalidate caches
        Cache::forget("agency_{$user->agency_id}_user_{$user->id}_properties_page_1");
        Cache::forget("agency_{$user->agency_id}_properties_page_1"); 

        return response()->json(['data' => $property], 201);
    }

    public function show($id): JsonResponse
    {
        // Cache the individual property for 60 minutes
        $propertyData = Cache::remember("property_show_{$id}", now()->addMinutes(60), function () use ($id) {
            $property = Property::with(['images', 'agent'])->findOrFail($id);
            
            // CRITICAL FIX: Resolve the Eloquent Model to a pure array BEFORE caching
            return (new PropertyResource($property))->response()->getData(true);
        });

        return response()->json($propertyData);
    }

    public function update(UpdatePropertyRequest $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $property->update($request->validated());

        Cache::forget("properties_page_1"); 
        $this->activity->log(
            auth()->id(), 
            "Updated property: {$property->title} (ID: {$property->id})", 
            $property->agency_id
        );
        
        return response()->json(['message' => 'Updated successfully', 'data' => $property]);
    }

    public function generatePublicSignedUrls(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string'
        ]);

        try {
            // Assuming you are using Laravel's S3 integration
            $url = \Illuminate\Support\Facades\Storage::disk('s3')
                ->temporaryUrl($request->path, now()->addMinutes(60));

            return response()->json(['signed_url' => $url]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to generate signed URL'], 500);
        }
    }

    public function attachImage(Request $request, Property $property): JsonResponse
    {
        $request->validate(['url' => ['required', 'string']]);

        \Illuminate\Support\Facades\Gate::authorize('update', $property);

        $image = $property->images()->create([
            's3_path' => $request->url, 
        ]);

        // FIX: Nuke the stale cache for this specific property so the frontend gets the new image array
        \Illuminate\Support\Facades\Cache::forget("property_show_{$property->id}");

        $this->activity->log(
            auth()->id(), 
            "Attached new image to property: {$property->title} (ID: {$property->id})", 
            $property->agency_id
        );
        return response()->json([
            'success' => true,
            'message' => 'Image attached successfully.',
            'image'   => $image,
        ], 201);
    }

    public function destroy(Property $property): JsonResponse
    {
        $this->authorize('delete', $property);

        $property->images()->delete(); 
        $property->delete();

        $this->activity->log(
            auth()->id(), 
            "Deleted property: {$property->title} (ID: {$property->id})", 
            $property->agency_id
        );
        return response()->json(['message' => 'Property deleted.'], 200);
    }
    /**
 * Unified Admin Index for Staff/Admins
 */
public function adminIndex(Request $request): JsonResponse
{
    $user = auth()->user();
    $page = $request->get('page', 1);

    // Start with the base query
    $query = Property::with(['agent', 'images'])->latest();

    // IF ADMIN: Remove the Global Scope so you can see EVERYTHING
    if ($user->role === 'admin') {
        $query = $query->withoutGlobalScope(\App\Scopes\AgencyScope::class);
    } 
    // IF AGENT: Keep the scope active (it's applied automatically, so we just filter)
    else {
        $query->where('agency_id', $user->agency_id);
    }

    $cacheKey = $user->role === 'admin' 
        ? "admin_all_properties_page_{$page}" 
        : "admin_agency_{$user->agency_id}_properties_page_{$page}";

    $responseData = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($query) {
        return PropertyResource::collection($query->paginate(20))->response()->getData(true);
    });

    return response()->json($responseData);
}
/**
 * Unified Status Update
 */
public function updateStatus(Request $request, Property $property): JsonResponse
{
    $request->validate(['status' => 'required|string']);
    $user = auth()->user();
    
    // ALLOW if Admin OR if they belong to the same agency
    if ($user->role !== 'admin' && $property->agency_id !== $user->agency_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $property->update(['status' => $request->status]);
    
    // Clear relevant caches
    Cache::forget("admin_agency_{$property->agency_id}_properties_page_1");
    Cache::forget("admin_all_properties_page_1"); // Add this to flush the Admin global cache
    Cache::forget("agency_{$property->agency_id}_user_{$property->user_id}_properties_page_1");
    
    $this->activity->log(
        auth()->id(), 
        "Updated property status to '{$request->status}' for property: {$property->title} (ID: {$property->id})", 
        $property->agency_id
    );
    
    return response()->json(['message' => 'Property status updated successfully.']);
}
}