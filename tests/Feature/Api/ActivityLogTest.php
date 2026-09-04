<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_requete_mutante_est_journalisee(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $log = ActivityLog::query()->where('route', 'auth.logout')->sole();

        $this->assertSame('POST', $log->method);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertNotNull($log->created_at);
    }

    public function test_les_lectures_ne_sont_pas_journalisees(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/auth/me')->assertOk();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_un_login_en_echec_est_journalise_sans_utilisateur(): void
    {
        User::factory()->create([
            'email' => 'buyer@demo.test',
            'password' => bcrypt('secret-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'buyer@demo.test',
            'password' => 'mauvais',
        ])->assertStatus(422);

        $log = ActivityLog::query()->where('route', 'auth.login')->sole();

        $this->assertNull($log->user_id);
        $this->assertSame(422, $log->status_code);
    }

    public function test_le_digest_du_payload_ne_contient_aucun_secret(): void
    {
        User::factory()->create([
            'email' => 'buyer@demo.test',
            'password' => bcrypt('secret-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'buyer@demo.test',
            'password' => 'secret-password',
        ])->assertOk();

        $log = ActivityLog::query()->where('route', 'auth.login')->sole();

        // Le digest est celui du corps SANS le mot de passe.
        $this->assertSame(
            hash('sha256', (string) json_encode(['email' => 'buyer@demo.test'])),
            $log->payload_digest,
        );
        // Et surtout : jamais le digest du corps complet.
        $this->assertNotSame(
            hash('sha256', (string) json_encode(['email' => 'buyer@demo.test', 'password' => 'secret-password'])),
            $log->payload_digest,
        );
    }
}
