<?php

use App\Models\Agency;
use App\Models\User;
use App\Models\SecureDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

describe('Vault Documents', function () {

    beforeEach(function () {
        $this->agency = Agency::factory()->create();
        $this->admin  = User::factory()->create([
            'role'      => 'admin',
            'agency_id' => $this->agency->id,
        ]);
        $this->agent  = User::factory()->create([
            'role'      => 'agent',
            'agency_id' => $this->agency->id,
        ]);

        // Fake the agent service so OCR/AI calls don't hit real endpoints
        Http::fake([
            '*agents/verify*' => Http::response([
                'ai_status' => 'ai_verified',
                'ai_confidence_score'    => 91.5,
                'ai_reasoning'           => 'Assessment: VERIFIED\nConfidence: 91.50%\nReasoning: Name matches with minor OCR variation.',
            ], 200),
        ]);
    });

    it('agent can store a document and metadata is persisted correctly', function () {
        $email = fake()->unique()->safeEmail(); // Generate unique email
        
        $response = $this->actingAs($this->agent)
            ->postJson('/api/v1/vault/documents', [
                'client_name'   => 'Jane Doe',
                'client_email'  => $email,
                'client_phone'  => fake()->phoneNumber(),
                's3_path'       => 'clients/jane-doe-123.jpg',
                'type'          => 'national_id_front',
                'temporary_url' => 'https://supabase.co/signed/doc.jpg?token=abc',
                'notes'         => 'Uploaded during onboarding.',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('secure_documents', [
            'agency_id'       => $this->agency->id,
            'uploaded_by'     => $this->agent->id,
            'type'            => 'national_id_front',
            's3_path' => 'clients/jane-doe-123.jpg',
        ]);
    });

    it('store creates a client user record via firstOrCreate on client_email', function () {
        $email = fake()->unique()->safeEmail(); // Generate unique email

        $this->actingAs($this->agent)
            ->postJson('/api/v1/vault/documents', [
                'client_name'   => 'Marcus Aurelius',
                'client_email'  => $email,
                'client_phone'  => fake()->phoneNumber(),
                's3_path'       => 'clients/marcus-123.pdf',
                'type'          => 'passport',
                'temporary_url' => 'https://supabase.co/signed/marcus-123.pdf?token=xyz',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'name'  => 'Marcus Aurelius',
        ]);
    });

    it('second store with same client_email reuses existing user and does not duplicate', function () {
        $email = 'repeat@example.com';

        // 1. Create the user
        User::factory()->create(['email' => $email, 'name' => 'Repeat Client']);

        // 2. Perform the action
        $this->actingAs($this->agent)
            ->postJson('/api/v1/vault/documents', [
                'client_name'   => 'Repeat Client',
                'client_email'  => $email,
                'client_phone'  => fake()->phoneNumber(),
                's3_path'       => 'clients/repeat-123.pdf',
                'type'          => 'kra_pin',
                'temporary_url' => 'https://supabase.co/signed/repeat-123.pdf?token=abc',
            ]);

        // 3. Instead of checking total count, check that only ONE user has this email
        $this->assertDatabaseCount('users', User::where('email', $email)->count());
        // Or better:
        expect(User::where('email', $email)->count())->toBe(1);
    });

    it('store validates required fields', function () {
        $this->actingAs($this->agent)
            ->postJson('/api/v1/vault/documents', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'client_name', 'client_email', 's3_path', 'type', 'temporary_url',
            ]);
    });

    it('agent can only see documents belonging to their agency', function () {
        $otherAgency = Agency::factory()->create();
        $otherAgent  = User::factory()->create(['role' => 'agent', 'agency_id' => $otherAgency->id]);

        SecureDocument::factory()->create(['agency_id' => $this->agency->id]);
        SecureDocument::factory()->create(['agency_id' => $otherAgency->id]);

        $response = $this->actingAs($this->agent)
            ->getJson('/api/v1/vault/documents');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        // All returned documents must belong to this agent's agency
        SecureDocument::whereIn('id', $ids)->each(function ($doc) {
            expect($doc->agency_id)->toBe($this->agency->id);
        });
    });

    it('admin can update document status to approved', function () {
        $doc = SecureDocument::factory()->create([
            'agency_id'           => $this->agency->id,
            'status' => 'pending_review',
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/vault/documents/{$doc->id}/status", [
                'status' => 'approved',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('secure_documents', [
            'id'                  => $doc->id,
            'status' => 'approved',
        ]);
    });

    it('agent cannot update document status — admin only', function () {
        $doc = SecureDocument::factory()->create([
            'agency_id'           => $this->agency->id,
            'status' => 'pending_review',
        ]);

        $this->actingAs($this->agent)
            ->patchJson("/api/v1/vault/documents/{$doc->id}/status", [
                'status' => 'approved',
            ])
            ->assertStatus(403);
    });

    it('status update rejects invalid status values', function () {
        $doc = SecureDocument::factory()->create(['agency_id' => $this->agency->id]);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/vault/documents/{$doc->id}/status", [
                'status' => 'rubber_stamped',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    });

    it('OCR failure does not block document creation — record created with null OCR fields', function () {
        // Simulate the agent service being down
        Http::fake([
            '*agents/verify*' => Http::response([], 500),
        ]);

        $response = $this->actingAs($this->agent)
            ->postJson('/api/v1/vault/documents', [
                'client_name'   => 'Henry Oduya',
                'client_email'  => 'henry@example.com',
                's3_path'       => 'clients/henry-123.jpg',
                'type'          => 'national_id_back',
                'temporary_url' => 'https://supabase.co/signed/henry-123.jpg?token=fail',
            ]);

        // Document still created despite OCR failure
        $response->assertStatus(201);

        $this->assertDatabaseHas('secure_documents', [
            's3_private_path' => 'clients/henry-123.jpg',
            'extracted_text'  => null,
        ]);
    });
});