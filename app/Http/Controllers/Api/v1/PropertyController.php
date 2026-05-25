<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\Property\PropertyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    /**
     * GET /v1/properties?page=1
     * Called by propertyApi.getAll() on the frontend.
     */
    public function index(): AnonymousResourceCollection
    {
        $properties = Property::latest()->paginate(20);

        return PropertyResource::collection($properties);
    }

    /**
     * POST /v1/properties
     */
    public function store(StorePropertyRequest $request): JsonResponse
    {
        $property = Property::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        return (new PropertyResource($property))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /v1/properties/{property}
     */
    public function show(Property $property): JsonResponse
    {
        return (new PropertyResource($property->load('images')))
            ->response();
    }

    /**
     * PUT /v1/properties/{property}
     */
    public function update(UpdatePropertyRequest $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $property->update($request->validated());

        return (new PropertyResource($property->fresh()))
            ->response();
    }

    /**
     * DELETE /v1/properties/{property}
     */
    public function destroy(Property $property): JsonResponse
    {
        $this->authorize('delete', $property);

        $property->delete();

        return response()->json(['message' => 'Property deleted.'], 200);
    }
}