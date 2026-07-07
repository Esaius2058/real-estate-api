<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyImage;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\Property\PropertyResource;
use App\Jobs\ProcessPropertyView;
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
        
        // 1. Double-submit protection
        $lock = Cache::lock('submit_property_user_' . $user->id, 5);
        if (!$lock->get()) {
            return response()->json(['message' => 'Please wait a moment before submitting again.'], 429);
        }

        try {
            // 2. Strict Subscription Enforcement
            $currentCount = Property::where('agency_id', $user->agency_id)->count();
            $limit = $user->propertyLimit();

            if ($currentCount >= $limit) {
                return response()->json([
                    'success' => false,
                    'message' => "Your agency has reached its plan limit of {$limit} listings. Upgrade your subscription to add more.",
                ], 403);
            }

            $validated = $request->validated();

            if (isset($validated['features'])) {
                $validated['amenities'] = $validated['features'];
                unset($validated['features']);
            }

            $imagesPayload = $request->input('images', []);
            unset($validated['images']);

            $validated['agency_id'] = $user->agency_id;
            $validated['user_id'] = $user->id; 

            // 3. Atomic Database Execution
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

    public function show(Request $request, $id): JsonResponse
    {
        $cacheKey = "property_show_{$id}";

        // Developer/Admin Cache Bypass
        if ($request->has('fresh') && $request->user()?->can('manage-system')) {
            Cache::forget($cacheKey);
        }

        $propertyData = Cache::remember($cacheKey, now()->addHours(24), function () use ($id) {
            $property = Property::with(['images', 'agent', 'agency'])
                ->whereIn('status', ['active', 'active_listing']) // STRICT SCOPE
                ->findOrFail($id);
            
            return (new PropertyResource($property))->response()->getData(true);
        });

        // Asynchronous View Tracking (Zero Frontend Block)
        if (!$request->user()?->can('manage-system')) {
            ProcessPropertyView::dispatchAfterResponse($id, $request->ip(), $request->user()?->id);
        }

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

    public function storeScrapedProperty(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'price'       => 'required|numeric',
            'location'    => 'required|string',
            'city'        => 'required|string',
            'bedrooms'    => 'nullable|integer',
            'baths'       => 'nullable|integer',
            'sqft'        => 'nullable|integer',
            'description' => 'required|string',
            'status'      => 'required|string',
            'type'        => 'nullable|string',
            'amenities'   => 'nullable|array',
            'images'      => 'required|array',
            'images.main' => 'nullable|string',
            'images.interior' => 'nullable|array',
            'images.exterior' => 'nullable|array',
        ]);

        $property = Property::create([
            'agency_id'   => $request->route('agencyId') ?? $request->input('agency_id', 1),
            'title'       => $validated['title'],
            'price'       => $validated['price'],
            'location'    => $validated['location'],
            'city'        => $validated['city'],
            'bedrooms'    => $validated['bedrooms'],
            'baths'       => $validated['baths'],
            'sqft'        => $validated['sqft'],
            'description' => $validated['description'],
            'type'        => $validated['type'],
            'amenities'   => json_encode($validated['amenities']),
            'status'      => $validated['status'],
        ]);

        if (!empty($validated['images']['main'])) {
            $property->images()->create(['s3_path' => $validated['images']['main'], 'is_primary' => 1]);
        }

        if (!empty($validated['images']['interior'])) {
            foreach ($validated['images']['interior'] as $path) {
                $property->images()->create(['s3_path' => $path, 'is_primary' => 0]);
            }
        }

        if (!empty($validated['images']['exterior'])) {
            foreach ($validated['images']['exterior'] as $path) {
                $property->images()->create(['s3_path' => $path, 'is_primary' => 0]);
            }
        }

        return response()->json(['message' => 'Scraped property ingested successfully', 'data' => $property], 201);
    }

    public function generatePublicSignedUrls(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string'
        ]);

        try {
            $url = Storage::disk('s3')->temporaryUrl($request->path, now()->addMinutes(60));
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

        $comps = Property::where('location', 'like', "%{$location}%")
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

            $property = Property::find($validated['property_id']);
            $forceRefresh = $request->boolean('force_refresh', false);

            if (!$forceRefresh && !empty($property->roi_forecast)) {
                return response()->json($property->roi_forecast);
            }

            $location = $validated['location'];
            $type = $validated['property_type'];
            $price = $validated['price'];

            $comps = Property::where('location', 'like', "%{$location}%")
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

            // Stamp generation time so frontend can show "loaded from cache" vs fresh
            $forecastData['generated_at'] = now()->toISOString();
            
            $property->update(['roi_forecast' => $forecastData]);

            return response()->json($forecastData);

        } catch (\Exception $e) {
            Log::error('ROI System Error: ' . $e->getMessage());
            return response()->json(['message' => 'Internal server error', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Property $property)
    {
        // Enforce Secure Policy (Overrides Victor's hardcoded check)
        $this->authorize('delete', $property);

        try {
            // Securely wipe remote storage using properly typed Eloquent relationships
            $images = $property->images; 
            
            foreach ($images as $image) {
                if (!empty($image->s3_path)) {
                    try {
                        Storage::disk('s3')->delete($image->s3_path);
                    } catch (\Exception $e) {
                        Log::warning("Failed to delete S3 image during property deletion: " . $image->s3_path);
                    }
                }
            }

            $propertyId = $property->id;
            
            // Delete DB records
            $property->images()->delete(); 
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