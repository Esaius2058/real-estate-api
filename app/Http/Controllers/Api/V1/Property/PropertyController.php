<?php

namespace App\Http\Controllers\Api\V1\Property;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\Property\PropertyResource;
use Illuminate\Http\JsonResponse;

class PropertyController extends Controller
{
    public function index(): JsonResponse
    {
        $properties = Property::latest()->paginate(20);
        return PropertyResource::collection($properties)->response();
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $property = Property::create($request->validated());
        return (new PropertyResource($property))->response()->setStatusCode(201);
    }

    public function show(Property $property): JsonResponse
    {
        return (new PropertyResource($property->load('images')))->response();
    }

    public function update(UpdatePropertyRequest $request, Property $property): JsonResponse
    {
        $property->update($request->validated());
        return (new PropertyResource($property))->response();
    }
}