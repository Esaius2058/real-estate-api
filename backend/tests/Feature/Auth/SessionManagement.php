<?php

use App\Models\User;
use App\Models\Agency;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class);

beforeEach(function () {
    $this->agency = Agency::factory()->create();
    $this->user = User::factory()->create([
        'agency_id' => $this->agency->id,
    ]);
});

test('logout revokes the current token and blocks subsequent requests', function () {
    $token = $this->user->createToken('test-token')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/logout')
        ->assertSuccessful();

    // Assert only THIS user's token is gone, ignoring background factory tokens
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $this->user->id,
    ]);

    // Force Laravel to forget the cached authenticated user
    $this->app->get('auth')->forgetGuards();

    // Subsequent request should fail
    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

test('me endpoint returns correct profile shape matching auth context', function () {
    $token = $this->user->createToken('test-token')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonStructure([
            'user' => [
                'id',
                'name',
                'role',
                'agency_id',
                'avatar_path',
            ],
            'profile' => [
                'id',
                'email',
                'role',
                'agencyId',
                'name',
                'agency' => [
                    'id',
                    'name',
                ],
            ],
        ]);
});