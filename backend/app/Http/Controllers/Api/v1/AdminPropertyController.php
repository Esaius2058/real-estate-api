<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AdminPropertyController extends Controller
{
    /**
     * Get all properties for the admin's agency.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $page = $request->get('page', 1);
        $cacheKey = "admin_agency_{$user->agency_id}_properties_page_{$page}";

        $properties = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($user) {
            return Property::where('agency_id', $user->agency_id)
                ->with(['agent', 'images'])
                ->latest()
                ->paginate(20);
        });

        return response()->json($properties);
        // Add this to AdminPropertyController@index
\Log::info("Admin Agency ID: " . auth()->user()->agency_id);
    }

    /**
     * Update the status of a property.
     */
    public function updateStatus(Request $request, Property $property): JsonResponse
    {
        $request->validate(['status' => 'required|string']);
        
        // Authorization: Ensure admin is managing their own agency
        if ($property->agency_id !== auth()->user()->agency_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $property->update(['status' => $request->status]);

        // Invalidate relevant caches
        $this->invalidateAgencyCaches(auth()->user()->agency_id);

        return response()->json(['message' => 'Property status updated successfully.']);
    }

    /**
     * Delete a property and its related records.
     */
 public function destroy($id): JsonResponse
{
    
    $property = Property::withTrashed()->find($id);
    if (!$property) return response()->json(['message' => 'Not found'], 404);
    
    // This assumes you added SoftDeletes trait to your Property model
    $property->delete(); 
    $this->invalidateAgencyCaches(auth()->user()->agency_id);
    return response()->json(['message' => 'Property moved to trash.']);
}

// 2. Permanent Delete (Wipe Data)
public function forceDestroy($id): JsonResponse
{
    // 1. Find the property
    $property = Property::withoutGlobalScope(\App\Scopes\AgencyScope::class)
                        ->withTrashed()
                        ->find($id);

    if (!$property) return response()->json(['message' => 'Not found'], 404);

   

    \Log::info("FORCE DESTROY: Policy bypassed, proceeding with deletion.");

    if ($property->images) {
        $property->images()->forceDelete();
    }

    // 2. Perform permanent deletion
    $property->forceDelete();
    
    $this->invalidateAgencyCaches(auth()->user()->agency_id);
    return response()->json(['message' => 'Property permanently removed.']);
}
     
private function invalidateAgencyCaches($agencyId): void
{
    // Clear specific property pages
    for ($page = 1; $page <= 20; $page++) {
        Cache::forget("admin_agency_{$agencyId}_properties_page_{$page}");
    }
    
    // If you use Tags (if your cache driver supports it, like Redis/Memcached), 
    // it's much better:
    // Cache::tags(['properties', "agency_{$agencyId}"])->flush();
}
} 