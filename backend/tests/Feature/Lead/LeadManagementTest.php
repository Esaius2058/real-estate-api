<?php

use App\Models\User;
use App\Models\Agency;
use App\Models\Property;
use App\Models\Lead;
use Tests\TestCase;
use Illuminate\Support\Facades\Queue; // Added Import

uses(TestCase::class);

beforeEach(function () {
    // Fake the queue to prevent external HTTP calls in observers/jobs
    Queue::fake();

    // Setup Agency A (Our target workspace)
    $this->agencyA = Agency::factory()->create();
    $this->agentA = User::factory()->create([
        'agency_id' => $this->agencyA->id,
        'role' => 'agent'
    ]);
    $this->propertyA = Property::factory()->create([
        'agency_id' => $this->agencyA->id,
        'user_id' => $this->agentA->id,
    ]);

    // Setup Agency B (For isolation testing)
    $this->agencyB = Agency::factory()->create();
    $this->agentB = User::factory()->create([
        'agency_id' => $this->agencyB->id,
        'role' => 'agent'
    ]);

    // Existing lead in Agency A
    $this->leadA = Lead::factory()->create([
        'agency_id' => $this->agencyA->id,
        'agent_id' => $this->agentA->id,
        'property_id' => $this->propertyA->id,
        'kanban_stage' => 'new',
    ]);
});

afterEach(function () {
    Lead::query()->delete();
    Property::query()->delete();
    User::query()->delete();
    Agency::query()->delete();
});

// --- 6.1 Public Ingestion ---

test('public client can submit a new lead', function () {
    $this->postJson('/api/v1/leads', [
        'property_id'     => $this->propertyA->id,
        'contact_name'    => 'Interested Buyer',
        'contact_email'   => 'buyer@example.com',
        'contact_phone'   => '1234567890',
        'estimated_value' => 500000,
        'notes'           => 'I would like to schedule a viewing.'
    ])
    ->assertSuccessful();

    $this->assertDatabaseHas('leads', [
        'email'       => 'buyer@example.com',
        'agent_id'    => $this->agentA->id, 
        'property_id' => $this->propertyA->id,
    ]);
});

// --- 6.2 Protected Reads & Tenant Isolation ---

test('agent can fetch leads for their agency', function () {
    $this->actingAs($this->agentA)
        ->getJson('/api/v1/leads')
        ->assertSuccessful()
        ->assertJsonFragment(['email' => $this->leadA->email]);
});

test('agent cannot fetch leads belonging to another agency', function () {
    $this->actingAs($this->agentB)
        ->getJson('/api/v1/leads')
        ->assertSuccessful()
        ->assertJsonMissing(['email' => $this->leadA->email]); 
});

// --- 6.3 Kanban Pipeline ---

test('staff can update lead kanban stage', function () {
    $this->actingAs($this->agentA)
        ->patchJson("/api/v1/leads/{$this->leadA->id}/kanban", [
            'kanban_stage' => 'contacted',
        ])
        ->assertSuccessful();

    $this->assertDatabaseHas('leads', [
        'id' => $this->leadA->id,
        'kanban_stage' => 'contacted',
    ]);
});

test('agent cannot update kanban stage of another agency lead', function () {
    $this->actingAs($this->agentB)
        ->patchJson("/api/v1/leads/{$this->leadA->id}/kanban", [
            'stage' => 'contacted',
        ])
        ->assertStatus(404);
});