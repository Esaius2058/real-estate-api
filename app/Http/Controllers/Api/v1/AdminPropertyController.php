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
    public function destroy(Property $property): JsonResponse
    {
        if ($property->agency_id !== auth()->user()->agency_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Deletes associated image records from the database
        $property->images()->delete(); 
        $property->delete();

        $this->invalidateAgencyCaches(auth()->user()->agency_id);

        return response()->json(['message' => 'Property removed successfully.']);
    }

    /**
     * Helper to clear agency-specific caches.
     */
    private function invalidateAgencyCaches($agencyId): void
    {
        // Example: If using a simple naming convention, you can manually clear keys
        // or better yet, implement Cache Tags if your driver supports them.
        Cache::forget("admin_agency_{$agencyId}_properties_page_1");
        Cache::forget("agency_{$agencyId}_properties_page_1");
    }
}