<?php

use App\Models\Agency;
use App\Models\Property;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class);

beforeEach(function () {
    // Setup two isolated agencies
    $this->agencyA = Agency::factory()->create();
    $this->agencyB = Agency::factory()->create();

    // Setup an agent for Agency A
    $this->agentA = User::factory()->create([
        'agency_id' => $this->agencyA->id,
        'role' => 'agent'
    ]);

    // Setup a property for Agency B
    $this->propertyB = Property::factory()->create([
        'agency_id' => $this->agencyB->id,
        'user_id' => User::factory()->create(['agency_id' => $this->agencyB->id])->id,
    ]);
});

describe('Tenant Isolation', function () {

    it('returns only the properties belonging to the authenticated agent\'s agency', function () {
        // Arrange: Create a property for Agency A
        Property::factory()->count(2)->create([
            'agency_id' => $this->agencyA->id,
            'user_id' => $this->agentA->id,
        ]);

        // Act: Agent A requests their properties
        $response = $this->actingAs($this->agentA, 'sanctum')
            ->getJson('/api/v1/agent/properties');

        // Assert: They get exactly 2 properties, completely ignoring Agency B's property
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    });

    it('blocks an agent from reading another agency\'s property', function () {
        // Act: Agent A tries to access Agency B's property
        $response = $this->actingAs($this->agentA, 'sanctum')
            ->getJson("/api/v1/properties/{$this->propertyB->id}");

        // Assert: Global scope drops the record, resulting in a 404
        $response->assertNotFound();
    });

    it('blocks an agent from updating another agency\'s property', function () {
        // Act: Agent A tries to modify Agency B's property
        $response = $this->actingAs($this->agentA, 'sanctum')
            ->putJson("/api/v1/properties/{$this->propertyB->id}", [
                'title' => 'Hijacked Property Title',
            ]);

        // Assert: 404 Not Found (or 403 if you are enforcing this via Policies)
        $response->assertNotFound();
    });

    it('blocks an agent from deleting another agency\'s property', function () {
        // Act: Agent A tries to delete Agency B's property
        $response = $this->actingAs($this->agentA, 'sanctum')
            ->deleteJson("/api/v1/properties/{$this->propertyB->id}");

        // Assert: 404 Not Found
        $response->assertNotFound();
        
        // Double-check the database to ensure the property still exists
        $this->assertDatabaseHas('properties', [
            'id' => $this->propertyB->id,
            'deleted_at' => null,
        ]);
    });

});