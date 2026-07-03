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

class PropertyController extends Controller
{
    public function index(): JsonResponse
    {
        $properties = Property::where('status', 'active')
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

        // Normalise status: map lowercase/snake_case to Title Case (e.g. "active" → "Active", "under_contract" → "Under Contract")
        if (isset($validated['status'])) {
            $statusMap = [
                'active'         => 'Active',
                'under_contract' => 'Under Contract',
                'closed'         => 'Closed',
                'expired'        => 'Expired',
                'Active'         => 'Active',
                'Under Contract' => 'Under Contract',
                'Closed'         => 'Closed',
                'Expired'        => 'Expired',
            ];
            $validated['status'] = $statusMap[$validated['status']] ?? 'Active';
        }

        // Strip and force secure ownership IDs
        $validated['agency_id'] = $user->agency_id;
        $validated['user_id'] = $user->id;

        // Ensure amenities is always an array for JSON cast
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

        return response()->json(['message' => 'Property deleted.'], 200);
    }

    /**
     * Save a base64-encoded image as a property image record.
     * Stores images in local storage as a fallback, or uses S3 if configured.
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
            // Try storing on S3 first
            \Illuminate\Support\Facades\Storage::disk('s3')->put($filename, $imageData, 'public');
            $path = $filename;
        } catch (\Exception $e) {
            // Fallback: store on local disk
            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $imageData);
            $path = $filename;
        }

        // Create the property image record
        $property->images()->create([
            's3_path' => $path,
            'is_primary' => $property->images()->count() === 0, // First image is primary
        ]);
    }
}
