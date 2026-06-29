<?php

use App\Models\User;
use App\Models\Agency;
use App\Models\Property;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

uses(TestCase::class);

beforeEach(function () {
    // Agency A (Our main test space)
    $this->agencyA = Agency::factory()->create();
    $this->adminA = User::factory()->create([
        'agency_id' => $this->agencyA->id,
        'role' => 'admin'
    ]);
    $this->agentA = User::factory()->create([
        'agency_id' => $this->agencyA->id,
        'role' => 'agent'
    ]);

    // Agency B (To test tenant isolation)
    $this->agencyB = Agency::factory()->create();
    $this->agentB = User::factory()->create([
        'agency_id' => $this->agencyB->id,
        'role' => 'agent'
    ]);

    // Property belonging to Agent A
    $this->propertyA = Property::factory()->create([
        'agency_id' => $this->agencyA->id,
        'user_id' => $this->agentA->id,
        'title' => 'Original Title',
    ]);
});

afterEach(function () {
    Property::query()->delete();
    User::query()->delete();
    Agency::query()->delete();
});

// --- 5.1 Property Creation ---

test('agent can create a property listing', function () {
    $this->actingAs($this->agentA)
        ->postJson('/api/v1/properties', [
            'title' => 'New Luxury Villa',
            'description' => 'A beautiful property.',
            'price' => 500000,
            // Added required fields below:
            'location' => '123 Test Street',
            'city' => 'Nakuru',
            'bedrooms' => 4,
            'baths' => 3,
            'sqft' => 2500,
            'status' => 'Active', // Check your FormRequest rules. If this still fails, try 'Active' or 'available'
        ])
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['id', 'title']]);

    $this->assertDatabaseHas('properties', [
        'title' => 'New Luxury Villa',
        'agency_id' => $this->agencyA->id,
        'user_id' => $this->agentA->id,
    ]);
});

test('agent can update their own property', function () {
    $this->actingAs($this->agentA)
        ->putJson("/api/v1/properties/{$this->propertyA->id}", [
            'title' => 'Updated Title',
        ])
        ->assertSuccessful();

    $this->assertDatabaseHas('properties', [
        'id' => $this->propertyA->id,
        'title' => 'Updated Title',
    ]);
});

test('agent cannot update property belonging to another agency', function () {
    $this->actingAs($this->agentB) // Agent from Agency B
        ->putJson("/api/v1/properties/{$this->propertyA->id}", [
            'title' => 'Hacked Title',
        ])
        ->assertStatus(404); // <-- Expect 404 to ensure complete tenant invisibility
});

test('anyone can view public property details', function () {
    $this->getJson("/api/v1/properties/{$this->propertyA->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.title', 'Original Title');
});


// --- 5.2 Image Handling ---

test('agent can attach image paths and retrieve flat string array', function () {
    $rawPath = 'properties/demo/image-1.jpg';
    $publicUrl = "https://supabase.co/storage/v1/object/public/bucket/{$rawPath}";

    // 1. Post with a full URL (Controller should normalize this to the raw path)
    $this->actingAs($this->agentA)
        ->postJson("/api/v1/properties/{$this->propertyA->id}/images", [
            'url' => $publicUrl, // Simulate frontend sending full URL
        ])
        ->assertSuccessful();

    // 2. Verify response maps to a flat string array (not nested objects)
    $response = $this->getJson("/api/v1/properties/{$this->propertyA->id}")
        ->assertSuccessful();

    // The API resource should flatten the relation into an array of strings
    $images = $response->json('data.images');
    
    expect($images)->toBeArray()
        ->and($images)->toHaveKeys(['main', 'interior', 'exterior'])
        ->and($images['main'])->toBe($rawPath); // Must be normalized, not the full URL
});

// --- 5.3 Cache Behaviour ---

test('property show endpoint caches data and invalidates on update or delete', function () {
    // Force clear just in case previous tests polluted it
    Cache::forget("property_show_{$this->propertyA->id}");

    // 1. First Request
    $this->getJson("/api/v1/properties/{$this->propertyA->id}")->assertSuccessful();

    // 2. Second Request (Reset log, then check)
    DB::flushQueryLog();
    $this->getJson("/api/v1/properties/{$this->propertyA->id}")->assertSuccessful();
    
    // Check if the cache hit actually happened
    $queries = DB::getQueryLog();
    expect(count($queries))->toBe(0, "Cache miss! Queries: " . json_encode($queries));

    // 2. Second Request (Cache Hit -> NO DB Query)
    DB::flushQueryLog();
    $this->getJson("/api/v1/properties/{$this->propertyA->id}")->assertSuccessful();
    $queriesAfterSecondCall = count(DB::getQueryLog());
    expect($queriesAfterSecondCall)->toBe(0); // 0 queries means it hit the cache successfully

    // 3. Invalidate via PUT
    $this->actingAs($this->agentA)
        ->putJson("/api/v1/properties/{$this->propertyA->id}", [
            'title' => 'Cache Buster Title',
        ])->assertSuccessful();

    // 4. Third Request (Cache cleared, should fetch new data)
    $this->getJson("/api/v1/properties/{$this->propertyA->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.title', 'Cache Buster Title');
        
    // 5. Invalidate via DELETE
    $this->actingAs($this->agentA)
        ->deleteJson("/api/v1/properties/{$this->propertyA->id}")
        ->assertSuccessful();
        
    expect(Cache::has("property_show_{$this->propertyA->id}"))->toBeFalse();
});

// --- 5.4 Listing Status Lifecycle ---

test('public index strictly filters out non-active properties', function () {
    // Create properties with different statuses
    $activeProp = Property::factory()->create(['agency_id' => $this->agencyA->id, 'status' => 'Active']);
    $contractProp = Property::factory()->create(['agency_id' => $this->agencyA->id, 'status' => 'Under Contract']);
    $soldProp = Property::factory()->create(['agency_id' => $this->agencyA->id, 'status' => 'Closed']);
    $deletedProp = Property::factory()->create(['agency_id' => $this->agencyA->id, 'status' => 'Active']);
    
    // Soft delete the last one
    $deletedProp->delete();

    $response = $this->getJson('/api/v1/properties')->assertSuccessful();
    $returnedIds = collect($response->json('data'))->pluck('id')->toArray();

    // Assertions
    expect($returnedIds)
        ->toContain($activeProp->id)       // Active is visible
        ->not->toContain($contractProp->id) // Under contract is hidden
        ->not->toContain($soldProp->id)     // Sold is hidden
        ->not->toContain($deletedProp->id); // Soft deleted is hidden
});