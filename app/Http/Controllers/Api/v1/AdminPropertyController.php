<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Http\Requests\Property\StorePropertyRequest;
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
     * Create a new property listing (admin override).
     */
    public function store(StorePropertyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        // Normalise status: map lowercase/snake_case to Title Case
        if (isset($validated['status'])) {
            $statusMap = [
                'active' => 'Active',
                'under_contract' => 'Under Contract',
                'closed' => 'Closed',
                'expired' => 'Expired',
                'Active' => 'Active',
                'Under Contract' => 'Under Contract',
                'Closed' => 'Closed',
                'Expired' => 'Expired',
            ];
            $validated['status'] = $statusMap[$validated['status']] ?? 'Active';
        }

        // Force ownership IDs
        $validated['agency_id'] = $user->agency_id;
        $validated['user_id'] = $user->id;

        if (empty($validated['amenities'])) {
            $validated['amenities'] = [];
        }

        $property = Property::create($validated);

        // Handle base64 images if provided
        if (!empty($validated['images']) && is_array($validated['images'])) {
            foreach ($validated['images'] as $base64Image) {
                $this->saveBase64Image($property, $base64Image);
            }
        }

        // Invalidate caches
        $this->invalidateAgencyCaches($user->agency_id);

        return response()->json(['data' => $property], 201);
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
        
        $property->delete(); 
        $this->invalidateAgencyCaches(auth()->user()->agency_id);
        return response()->json(['message' => 'Property moved to trash.']);
    }

    /**
     * Permanent Delete (Wipe Data)
     */
    public function forceDestroy($id): JsonResponse
    {
        $property = Property::withoutGlobalScope(\App\Scopes\AgencyScope::class)
                            ->withTrashed()
                            ->find($id);

        if (!$property) return response()->json(['message' => 'Not found'], 404);

        \Log::info("FORCE DESTROY: Policy bypassed, proceeding with deletion.");

        if ($property->images) {
            $property->images()->forceDelete();
        }

        $property->forceDelete();
        
        $this->invalidateAgencyCaches(auth()->user()->agency_id);
        return response()->json(['message' => 'Property permanently removed.']);
    }

    /**
     * Save a base64-encoded image as a property image record.
     */
    private function saveBase64Image(Property $property, string $base64String): void
    {
        // Strip data URI prefix if present (e.g. "data:image/png;base64,iVBOR...")
        if (str_contains($base64String, 'base64,')) {
            $base64String = substr($base64String, strpos($base64String, 'base64,') + 7);
        }

        $imageData = base64_decode($base64String);
        if ($imageData === false) {
            return;
        }

        // Generate a unique filename
        $filename = 'properties/' . $property->id . '/' . uniqid() . '.jpg';
        
        try {
            \Illuminate\Support\Facades\Storage::disk('s3')->put($filename, $imageData, 'public');
            $path = $filename;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $imageData);
            $path = $filename;
        }

        $property->images()->create([
            's3_path' => $path,
            'is_primary' => $property->images()->count() === 0,
        ]);
    }
      
    private function invalidateAgencyCaches($agencyId): void
    {
        for ($page = 1; $page <= 20; $page++) {
            Cache::forget("admin_agency_{$agencyId}_properties_page_{$page}");
        }
    }
}