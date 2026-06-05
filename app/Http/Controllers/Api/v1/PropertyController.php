<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\Property\PropertyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class PropertyController extends Controller
{
    /**
     * GET /v1/properties?page=1
     */
    public function index(Request $request)
    {
        // Get the requested page, default to 1
        $page = $request->get('page', 1);

        // Define a unique cache key for this specific page
        $cacheKey = "properties_page_{$page}";

        // Remember the query results for 60 minutes
        $properties = Cache::remember($cacheKey, now()->addMinutes(60), function () {
            // This closure ONLY executes if the cache is empty/expired
            return Property::with(['images', 'agent'])
                ->where('status', 'active')
                ->latest()
                ->paginate(15); // Adjust pagination as needed
        });

        return response()->json($properties);
    }

    /**
     * POST /v1/properties
     */
    public function store(Request $request)
    {
        // Create the new property
        $property = Property::create($request->all());

        // CRITICAL: Clear the first page of the index cache so the new listing appears immediately
        Cache::forget("properties_page_1"); 

        return response()->json(['message' => 'Property created successfully', 'data' => $property], 201);
    }

    /**
     * GET /v1/properties/{property}
     */
    public function show($id)
    {
        // Cache the individual property for 60 minutes based on its unique ID
        $property = Cache::remember("property_show_{$id}", now()->addMinutes(60), function () use ($id) {
            return Property::with(['images', 'agent'])->findOrFail($id);
        });

        return response()->json($property);
    }

    /**
     * PUT /v1/properties/{property}
     */
    public function update(Request $request, $id)
    {
        $property = Property::findOrFail($id);
        $property->update($request->all());

        // CRITICAL: You must invalidate the cache when a record is updated, 
        // otherwise users will continue to see stale data.
        // For simplicity, we flush the specific page cache, or use Cache Tags if using Redis.
        Cache::forget("properties_page_1"); 
        
        return response()->json(['message' => 'Updated successfully', 'data' => $property]);
    }

    /**
     * POST /v1/properties/{property}/images
     */
    public function attachImage(Request $request, Property $property): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'url'],
        ]);

        $this->authorize('update', $property);

        $image = $property->images()->create([
            's3_path' => $request->url, // fixed: was writing to 'url', column is 's3_path'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Image attached successfully.',
            'image'   => $image,
        ], 201);
    }

    /**
     * DELETE /v1/properties/{property}
     */
    public function destroy(Property $property): JsonResponse
    {
        $this->authorize('delete', $property);

        $property->images()->delete(); // clean up related images first
        $property->delete();

        return response()->json(['message' => 'Property deleted.'], 200);
    }
}