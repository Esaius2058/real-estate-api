<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\Property\PropertyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PropertyController extends Controller
{
    public function index(): JsonResponse
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

        // Invalidate caches
        Cache::forget("agency_{$user->agency_id}_user_{$user->id}_properties_page_1");
        Cache::forget("agency_{$user->agency_id}_properties_page_1"); 

        return response()->json(['data' => $property], 201);
    }

    public function show($id): JsonResponse
    {
        // Cache the individual property for 60 minutes
        $property = Cache::remember("property_show_{$id}", now()->addMinutes(60), function () use ($id) {
            return Property::with(['images', 'agent'])->findOrFail($id);
        });

        return response()->json($property);
    }

    public function update(UpdatePropertyRequest $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $property->update($request->validated());

        Cache::forget("properties_page_1"); 
        
        return response()->json(['message' => 'Updated successfully', 'data' => $property]);
    }

    public function attachImage(Request $request, Property $property): JsonResponse
    {
        $request->validate(['url' => ['required', 'url']]);

        $this->authorize('update', $property);

        $image = $property->images()->create([
            's3_path' => $request->url, 
        ]);

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
}