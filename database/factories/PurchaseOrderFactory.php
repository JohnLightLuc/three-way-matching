<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reference' => 'PO-'.fake()->unique()->numerify('######'),
            'supplier_id' => Supplier::factory(),
            'project_id' => Project::factory(),
            'status' => 'open',
            'currency' => 'XOF',
            'notes' => null,
        ];
    }

    /** Rattache le PO à un fournisseur existant. */
    public function forSupplier(Supplier $supplier): static
    {
        return $this->state(['supplier_id' => $supplier->id]);
    }

    /** Rattache le PO à un projet existant. */
    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }
}
