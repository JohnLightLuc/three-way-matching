<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_retourne_un_token_utilisable(): void
    {
        $user = User::factory()->create([
            'email' => 'buyer@demo.test',
            'password' => bcrypt('secret-password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'buyer@demo.test',
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'is_reviewer']])
            ->assertJsonPath('user.id', $user->id);

        $token = $response->json('token');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'buyer@demo.test');
    }

    public function test_login_avec_mauvais_mot_de_passe_est_rejete(): void
    {
        User::factory()->create([
            'email' => 'buyer@demo.test',
            'password' => bcrypt('secret-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'buyer@demo.test',
            'password' => 'mauvais',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_avec_email_inconnu_est_rejete(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'personne@demo.test',
            'password' => 'peu-importe',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_une_route_protegee_exige_un_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revoque_le_token_courant(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Nouvelle requête = guard non résolu (le middleware auth garde l'utilisateur en cache sur le guard).
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_reflete_le_role_reviewer(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $token = $reviewer->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_reviewer', true);
    }
}
