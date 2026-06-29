<?php

use App\Models\User;
use App\Models\Agency;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->joinCode = 'CODE-' . strtoupper(Str::random(6));

    $this->agency = Agency::factory()->create([
        'name' => 'Original Agency Name',
        'join_code' => $this->joinCode,
    ]);

    $this->admin = User::factory()->create([
        'agency_id' => $this->agency->id,
        'role' => 'admin', 
    ]);

    $this->agent = User::factory()->create([
        'agency_id' => $this->agency->id,
        'role' => 'agent', 
    ]);

    $this->unassignedUser = User::factory()->create([
        'agency_id' => null,
        'role' => 'agent', 
    ]);
});

// Manual cleanup to replace RefreshDatabase
afterEach(function () {
    User::query()->delete();
    Agency::query()->delete();
});

// --- 4.1 Agency Creation ---

test('unassigned user can initialize a workspace and is assigned as admin', function () {
    $this->actingAs($this->unassignedUser)
        ->postJson('/api/v1/vault/initialize-workspace', [
            'agency_name' => 'New Real Estate Co',
            'role' => 'admin',
        ])
        ->assertSuccessful()
        ->assertJsonStructure([
            'message',
            'agency' => [
                'id',
                'name',
                'join_code',
            ]
]);

    $this->unassignedUser->refresh();
    
    expect($this->unassignedUser->agency_id)->not->toBeNull()
        ->and(strtolower($this->unassignedUser->role))->toBe('admin');
});

test('user already in an agency cannot initialize a new workspace', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/vault/initialize-workspace', [
            'agency_name' => 'Another Co',
            'role' => 'admin',
        ])
        ->assertStatus(409);
});

// --- 4.2 Join Flow ---

test('new agent can register using a valid case-insensitive join code', function () {
    $this->postJson('/api/v1/register', [
        'name' => 'New Agent',
        'email' => 'new.agent@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'agency_code' => strtolower($this->joinCode), 
    ])->assertSuccessful();

    $this->assertDatabaseHas('users', [
        'email' => 'new.agent@example.com',
        'agency_id' => $this->agency->id,
    ]);
});

test('registration with an invalid join code returns 422', function () {
    $this->postJson('/api/v1/register', [
        'name' => 'New Agent',
        'email' => 'invalid.agent@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'agency_code' => 'INVALID-CODE-999',
    ])->assertStatus(422)
      // Corrected: assert against the key the API actually rejects
      ->assertJsonValidationErrors(['agency_code']); 
});

// --- 4.3 Agency Update ---

test('admin can update agency name', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/agency/{$this->agency->id}", [ // <-- Changed to putJson
            'name' => 'Updated Agency Name',
        ])
        ->assertSuccessful();
});

test('agent cannot update agency name', function () {
    $this->actingAs($this->agent)
        ->putJson("/api/v1/agency/{$this->agency->id}", [ // <-- Changed to putJson
            'name' => 'Rogue Update',
        ])
        ->assertForbidden();
});