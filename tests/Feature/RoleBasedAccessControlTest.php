<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleBasedAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, array<int, string>}>
     */
    public static function roleAccessProvider(): array
    {
        return [
            'admin' => ['admin', '/admin/dashboard', ['/inventory-officer/inventory', '/sales-officer/sales', '/dispatch/fuel-lifting', '/driver/fuel-lifting']],
            'inventory officer' => ['inventory_officer', '/inventory-officer/inventory', ['/admin/dashboard', '/sales-officer/sales', '/dispatch/fuel-lifting', '/driver/fuel-lifting']],
            'sales officer' => ['sales_officer', '/sales-officer/sales', ['/admin/dashboard', '/inventory-officer/inventory', '/dispatch/fuel-lifting', '/driver/fuel-lifting']],
            'dispatch officer' => ['dispatch_officer', '/dispatch/fuel-lifting', ['/admin/dashboard', '/inventory-officer/inventory', '/sales-officer/sales', '/driver/fuel-lifting']],
            'driver' => ['driver', '/driver/fuel-lifting', ['/admin/dashboard', '/inventory-officer/inventory', '/sales-officer/sales', '/dispatch/fuel-lifting']],
        ];
    }

    public function test_guest_is_redirected_from_protected_pages_to_login(): void
    {
        $this->get('/admin/dashboard')
            ->assertRedirect('/login');
    }

    /**
     * @param array<int, string> $blockedUrls
     */
    #[DataProvider('roleAccessProvider')]
    public function test_authenticated_users_can_only_access_their_role_pages(string $role, string $allowedUrl, array $blockedUrls): void
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get($allowedUrl)
            ->assertOk();

        foreach ($blockedUrls as $blockedUrl) {
            $this->actingAs($user)
                ->get($blockedUrl)
                ->assertForbidden();
        }
    }

    public function test_login_uses_database_role_and_blocks_wrong_role_selection(): void
    {
        $user = User::factory()->create([
            'email' => 'inventory@example.com',
            'role' => 'inventory_officer',
            'status' => 'active',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'username' => $user->email,
            'role' => 'sales_officer',
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_login_redirects_to_the_authenticated_users_role_dashboard(): void
    {
        $user = User::factory()->create([
            'email' => 'sales@example.com',
            'role' => 'sales_officer',
            'status' => 'active',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'username' => $user->email,
            'role' => 'sales_officer',
            'password' => 'password',
        ])->assertRedirect(route('sales-officer.sales'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'role' => 'admin',
            'status' => 'inactive',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'username' => $user->email,
            'role' => 'admin',
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
