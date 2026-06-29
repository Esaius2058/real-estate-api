<?php

use App\Models\Agency;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class);

beforeEach(function () {
    $this->agencyA = Agency::factory()->create();
    $this->agencyB = Agency::factory()->create();

    // Check your migration: replace 'admin' / 'agent' if your DB expects 'Admin' / 'Agent'
    $this->adminA = User::factory()->create([
        'agency_id' => $this->agencyA->id,
        'role' => 'admin',
    ]);

    $this->agentA = User::factory()->create([
        'agency_id' => $this->agencyA->id,
        'role' => 'agent',
    ]);

    $this->otherAgentA = User::factory()->create([
        'agency_id' => $this->agencyA->id,
        'role' => 'agent',
    ]);

    $this->propertyAgentA = Property::factory()->create([
        'agency_id' => $this->agencyA->id,
        'user_id' => $this->agentA->id,
    ]);

    $this->propertyAgencyB = Property::factory()->create([
        'agency_id' => $this->agencyB->id,
        'user_id' => User::factory()->create(['agency_id' => $this->agencyB->id])->id,
    ]);

    // Raw insert to bypass missing factory errors if SecureDocumentFactory isn't built yet
    $this->documentId = DB::table('secure_documents')->insertGetId([
        'agency_id' => $this->agencyA->id,
        'uploaded_by' => $this->adminA->id, // The agent/admin who uploaded it
        'type' => 'national_id',
        's3_path' => 'clients/sample-path.jpg',
        'status' => 'pending_review',
        'ai_verification_status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('an agent cannot update document status', function () {
    $this->actingAs($this->agentA)
        ->patchJson("/api/v1/vault/documents/{$this->documentId}/status", ['status' => 'approved'])
        ->assertForbidden();
});

test('an agent cannot view the agents list', function () {
    $this->actingAs($this->agentA)
        ->getJson('/api/v1/agents')
        ->assertForbidden();
});

test('an agent cannot delete a property they do not own', function () {
    $this->actingAs($this->otherAgentA)
        ->deleteJson("/api/v1/properties/{$this->propertyAgentA->id}")
        ->assertForbidden();
});

test('an admin can view the agents list', function () {
    $this->actingAs($this->adminA)
        ->getJson('/api/v1/agents')
        ->assertSuccessful();
});

test('an admin can delete any property within their agency', function () {
    $this->withoutExceptionHandling(); // <-- Add this temporarily
    
    $this->actingAs($this->adminA)
        ->deleteJson("/api/v1/properties/{$this->propertyAgentA->id}")
        ->dump()
        ->assertSuccessful();
});

test('an admin cannot act on a property belonging to a different agency', function () {
    $this->actingAs($this->adminA)
        ->deleteJson("/api/v1/properties/{$this->propertyAgencyB->id}")
        ->assertNotFound(); // Changed from assertForbidden
});