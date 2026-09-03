<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AuthWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_ecran_de_connexion_rend_la_page_inertia(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_une_connexion_valide_ouvre_la_session_et_est_journalisee(): void
    {
        $user = User::factory()->create([
            'email' => 'buyer@demo.test',
            'password' => bcrypt('secret-password'),
        ]);

        $this->post('/login', ['email' => 'buyer@demo.test', 'password' => 'secret-password'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('activity_logs', [
            'route' => 'login',
            'user_id' => $user->id,
            'status_code' => 200,
        ]);
    }

    public function test_une_connexion_invalide_est_refusee_et_journalisee_sans_utilisateur(): void
    {
        User::factory()->create([
            'email' => 'buyer@demo.test',
            'password' => bcrypt('secret-password'),
        ]);

        $this->from('/login')
            ->post('/login', ['email' => 'buyer@demo.test', 'password' => 'mauvais'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['route' => 'login', 'user_id' => null, 'status_code' => 422]);
    }

    public function test_un_invite_est_redirige_vers_la_connexion(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/invoices')->assertRedirect('/login');
    }

    public function test_le_tableau_de_bord_est_accessible_authentifie(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    }

    public function test_deconnexion(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['route' => 'logout', 'user_id' => $user->id]);
    }
}
