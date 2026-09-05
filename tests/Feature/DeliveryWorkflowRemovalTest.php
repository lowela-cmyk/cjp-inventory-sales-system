<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DeliveryWorkflowRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_delivery_routes_and_pages_are_not_registered(): void
    {
        foreach ([
            'dispatch.fuel-lifting.deliveries.store',
            'dispatch.fuel-lifting.deliveries.status',
            'dispatch.fuel-lifting.deliveries.assignment',
            'admin.fuel-lifting.deliveries.store',
            'admin.fuel-lifting.deliveries.status',
            'admin.fuel-lifting.deliveries.assignment',
            'driver.assigned-deliveries',
            'driver.assigned-deliveries.completed',
            'driver.assigned-deliveries.pickup',
            'driver.assigned-deliveries.status',
        ] as $routeName) {
            $this->assertFalse(Route::has($routeName), $routeName.' should not be registered.');
        }

        $this->assertFileDoesNotExist(resource_path('views/driver/assigned-deliveries.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/driver/partials/assigned-deliveries-table.blade.php'));
    }

    public function test_fuel_lifting_pages_remain_available_after_delivery_workflow_removal(): void
    {
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);

        $this->actingAs($dispatchOfficer)
            ->get(route('dispatch.fuel-lifting'))
            ->assertOk()
            ->assertSee('Fuel Lifting Operations');

        $this->actingAs($driver)
            ->get(route('driver.fuel-lifting'))
            ->assertOk()
            ->assertSee('Fuel Lifting Operations');
    }
}
