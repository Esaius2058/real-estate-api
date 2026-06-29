<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyImage;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\Property\PropertyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    public function index(): JsonResponse
    {
        $properties = Property::where('status', 'active')
            ->with(['images', 'agency'])
            ->latest()
            ->limit(20)
            ->get();

        return response()->json(['data' => $properties]);
    }

    public function agencyIndex(Request $request)
    {
        $user = auth()->user();
        $page = request()->get('page', 1);

        $cacheKey = "agency_{$user->agency_id}_user_{$user->id}_properties_page_{$page}";

        $responseData = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($user) {
            
            $query = Property::with(['agent', 'images'])->latest();

            if ($user->role === 'agent') {
                $query->where('user_id', $user->id); 
            }

            return PropertyResource::collection($query->paginate(20))->response()->getData(true);
        });
        
        return response()->json($responseData);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $user = auth()->user();
        $lock = Cache::lock('submit_property_user_' . $user->id, 5);

        if (!$lock->get()) {
            return response()->json(['message' => 'Please wait a moment before submitting again.'], 429);
        }

        try {
            $validated = $request->validated();

            if (isset($validated['features'])) {
                $validated['amenities'] = $validated['features'];
                unset($validated['features']);
            }

            $imagesPayload = $request->input('images', []);
            unset($validated['images']);

            $validated['agency_id'] = $user->agency_id;
            $validated['user_id'] = $user->id; 

            // Execute DB transaction to ensure atomic property and image creation
            $property = DB::transaction(function () use ($validated, $imagesPayload) {
                
                $property = Property::create($validated);
                $imageRecords = [];

                if (!empty($imagesPayload['main'])) {
                    $imageRecords[] = [
                        'property_id' => $property->id,
                        's3_path'     => $imagesPayload['main'],
                        'is_primary'  => 1,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ];
                }

                $secondaryImages = array_merge(
                    $imagesPayload['interior'] ?? [],
                    $imagesPayload['exterior'] ?? []
                );

                foreach ($secondaryImages as $path) {
                    $imageRecords[] = [
                        'property_id' => $property->id,
                        's3_path'     => $path,
                        'is_primary'  => 0,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ];
                }

                if (!empty($imageRecords)) {
                    PropertyImage::insert($imageRecords);
                }

                return $property;
            });

            Cache::forget("agency_{$user->agency_id}_user_{$user->id}_properties_page_1");
            Cache::forget("agency_{$user->agency_id}_properties_page_1"); 

            return response()->json([
                'message' => 'Property committed successfully.',
                'data' => $property->load('images') 
            ], 201);

        } catch (\Exception $e) {
            Log::error('Property Submission Failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to save property. Please check server logs.'], 500);
        } finally {
            $lock->release();
        }
    }

    public function show($id): JsonResponse
    {
        $cacheKey = "property_show_{$id}";

        $propertyData = Cache::remember($cacheKey, now()->addHours(24), function () use ($id) {
            // Eager load ALL relationships used by PropertyResource
            $property = Property::with(['images', 'agent', 'agency'])->findOrFail($id);
            
            // Return the array data, not the JsonResponse object
            return (new PropertyResource($property))->response()->getData(true);
        });

        return response()->json($propertyData);
    }
    

    public function update(UpdatePropertyRequest $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $property->update($request->validated());

        Cache::forget("properties_page_1"); 
        Cache::forget("property_show_{$property->id}");
        
        return response()->json(['message' => 'Updated successfully', 'data' => $property]);
    }

    public function generatePublicSignedUrls(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string'
        ]);

        try {
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

        Gate::authorize('update', $property);

        $rawPath = Str::contains($request->url, 'http') 
            ? Str::after($request->url, '/public/bucket/') 
            : $request->url;

        // FIX: Set is_primary if it's the first image, or based on your UI logic
        $isPrimary = $property->images()->count() === 0 ? 1 : 0;

        $image = $property->images()->create([
            's3_path' => $rawPath,
            'is_primary' => $isPrimary,
        ]);

        Cache::forget("property_show_{$property->id}");

        return response()->json([
            'success' => true,
            'message' => 'Image attached successfully.',
            'image'   => $image,
        ], 201);
    }

    public function generateMarketingCopy(Request $request)
    {
        $validated = $request->validate([
            'property_type'   => 'required|string',
            'location'        => 'required|string',
            'price'           => 'required|string',
            'bedrooms'        => 'nullable|integer',
            'bathrooms'       => 'nullable|integer',
            'features'        => 'array',
            'target_audience' => 'nullable|string',
        ]);

        try {
            $response = Http::timeout(60)
            ->withToken($request->bearerToken())
            ->post(config('services.agent.url', 'http://127.0.0.1:8001') . '/agents/marketing/generate', [
                'property_type'   => $validated['property_type'],
                'location'        => $validated['location'],
                'price'           => $validated['price'],
                'bedrooms'        => $validated['bedrooms'],
                'bathrooms'       => $validated['bathrooms'],
                'features'        => $validated['features'] ?? [],
                'target_audience' => $validated['target_audience'] ?? 'potential buyers',
            ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            Log::error('Marketing Agent Failed', ['response' => $response->body()]);
            return response()->json(['message' => 'AI generation failed. Please try again.'], 500);
            
        } catch (\Exception $e) {
            Log::error('Marketing Agent Exception', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not reach the AI service.'], 500);
        }
    }

    public function getComps(Request $request)
    {
        $location = $request->query('location');
        $type = $request->query('type', 'Apartment');
        $price = $request->query('price');
        $excludeId = $request->query('exclude_id');

        $comps = \App\Models\Property::where('location', 'like', "%{$location}%")
            ->where('type', $type)
            ->when($excludeId, function($query, $excludeId) {
                return $query->where('id', '!=', $excludeId);
            })
            ->whereBetween('price', [$price * 0.75, $price * 1.25])
            ->whereIn('status', ['active', 'sold'])
            ->limit(5)
            ->get(['id', 'title', 'price', 'status', 'bedrooms', 'baths']);

        return response()->json(['data' => $comps]);
    }

    public function predictROI(Request $request)
    {
        try {
            $validated = $request->validate([
                'property_id' => 'required|integer|exists:properties,id',
                'property_title' => 'required|string',
                'location' => 'required|string',
                'price' => 'required|numeric',
                'property_type' => 'required|string',
                'force_refresh' => 'boolean',
            ]);

            $property = \App\Models\Property::find($validated['property_id']);
            $forceRefresh = $request->boolean('force_refresh', false);

            if (!$forceRefresh && !empty($property->roi_forecast)) {
                return response()->json($property->roi_forecast);
            }

            $location = $validated['location'];
            $type = $validated['property_type'];
            $price = $validated['price'];

            $comps = \App\Models\Property::where('location', 'like', "%{$location}%")
                ->where('type', $type)
                ->where('id', '!=', $property->id)
                ->whereBetween('price', [$price * 0.75, $price * 1.25])
                ->whereIn('status', ['active', 'sold'])
                ->limit(5)
                ->get(['id', 'title', 'price', 'status', 'bedrooms', 'baths'])
                ->toArray();

            $payload = $validated;
            $payload['comparable_listings'] = $comps;

            $agentUrl = env('AGENT_SERVICE_URL', 'http://127.0.0.1:8001');
            Log::info("Sending AI request to: " . $agentUrl . '/agents/roi-forecast');

            $response = Http::timeout(60)->post($agentUrl . '/agents/roi-forecast', $payload);

            if ($response->failed()) {
                Log::error('AI Service Error: ' . $response->body());
                return response()->json(['message' => 'Analysis Service unavailable', 'error' => $response->body()], 500);
            }

            $forecastData = $response->json();

            $property->update(['roi_forecast' => $forecastData]);

            return response()->json($forecastData);

        } catch (\Exception $e) {
            Log::error('ROI System Error: ' . $e->getMessage());
            return response()->json(['message' => 'Internal server error', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(\App\Models\Property $property)
    {
        if (auth()->user()->role === 'agent' && $property->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized. Agents can only delete their own properties.'], 403);
        }

        try {
            $images = $property->images ?? [];
            $pathsToDelete = [];
            
            if (!empty($images['main'])) {
                $pathsToDelete[] = $images['main'];
            }
            if (!empty($images['interior']) && is_array($images['interior'])) {
                $pathsToDelete = array_merge($pathsToDelete, $images['interior']);
            }
            if (!empty($images['exterior']) && is_array($images['exterior'])) {
                $pathsToDelete = array_merge($pathsToDelete, $images['exterior']);
            }

            if (!empty($pathsToDelete)) {
                foreach ($pathsToDelete as $path) {
                    try {
                        Storage::disk('s3')->delete($path);
                    } catch (\Exception $e) {
                        Log::warning("Failed to delete Supabase image during property deletion: " . $path);
                    }
                }
            }

            $propertyId = $property->id;
            $property->delete();

            Cache::forget("property_show_{$propertyId}");

            return response()->json(['message' => 'Property securely deleted'], 200);

        } catch (\Exception $e) {
            Log::error('Property Deletion Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to delete property',
                'error' => $e->getMessage() 
            ], 500);
        }
    }
}