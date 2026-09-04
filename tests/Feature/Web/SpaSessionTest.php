<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SpaSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_session_web_authentifie_les_appels_api(): void
    {
        // Pas de token Bearer : c'est le cookie de session (mode SPA Sanctum) qui authentifie.
        $this->actingAs(User::factory()->create())
            ->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_les_endpoints_referentiel_repondent(): void
    {
        Supplier::factory()->count(2)->create();
        Project::factory()->create();

        $this->actingAs(User::factory()->create());

        $this->getJson('/api/suppliers')->assertOk()->assertJsonCount(2)->assertJsonStructure([['id', 'code', 'name']]);
        $this->getJson('/api/projects')->assertOk()->assertJsonCount(1);
    }

    #[DataProvider('pages')]
    public function test_les_pages_rendent_le_bon_composant_inertia(string $url, string $component): void
    {
        $this->actingAs(User::factory()->create())
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    /** @return array<string, array{string, string}> */
    public static function pages(): array
    {
        return [
            'liste PO' => ['/purchase-orders', 'PurchaseOrders/Index'],
            'création PO' => ['/purchase-orders/create', 'PurchaseOrders/Create'],
            'liste factures' => ['/invoices', 'Invoices/Index'],
            'revue' => ['/review', 'Review/Index'],
        ];
    }
}
