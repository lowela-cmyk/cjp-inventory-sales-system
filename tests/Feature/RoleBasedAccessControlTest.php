<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
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

    /**
     * @return array<string, array{string, string}>
     */
    public static function roleDashboardProvider(): array
    {
        return [
            'admin' => ['admin', 'admin.dashboard'],
            'inventory officer' => ['inventory_officer', 'inventory-officer.inventory'],
            'sales officer' => ['sales_officer', 'sales-officer.sales'],
            'dispatch officer' => ['dispatch_officer', 'dispatch.fuel-lifting'],
            'driver' => ['driver', 'driver.fuel-lifting'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function protectedUrlProvider(): array
    {
        $urls = ['/dashboard', '/home'];

        foreach (self::roleUrlMap() as $roleUrls) {
            array_push($urls, ...$roleUrls);
        }

        return collect($urls)
            ->unique()
            ->mapWithKeys(fn (string $url): array => [$url => [$url]])
            ->all();
    }

    /**
     * @return array<string, array{string, array<int, string>, array<int, string>}>
     */
    public static function fullRoleAccessProvider(): array
    {
        $roleUrlMap = self::roleUrlMap();

        return collect($roleUrlMap)
            ->mapWithKeys(function (array $allowedUrls, string $role) use ($roleUrlMap): array {
                $blockedUrls = collect($roleUrlMap)
                    ->except($role)
                    ->flatten()
                    ->unique()
                    ->values()
                    ->all();

                return [$role => [$role, $allowedUrls, $blockedUrls]];
            })
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function roleUrlMap(): array
    {
        return [
            'admin' => [
                '/admin',
                '/admin/dashboard',
                '/admin/inventory',
                '/admin/ledger',
                '/admin/fuel-lifting',
                '/admin/sales',
                '/admin/reports',
                '/admin/alerts',
                '/admin/user-management',
            ],
            'inventory_officer' => [
                '/inventory-officer',
                '/inventory-officer/inventory',
                '/inventory-officer/inventory/stock-in',
                '/inventory-officer/inventory/stock-out',
                '/inventory-officer/ledger',
                '/inventory-officer/ledger/transactions',
                '/inventory-officer/alerts',
            ],
            'sales_officer' => [
                '/sales-officer',
                '/sales-officer/sales',
                '/sales-officer/sales/customers',
                '/sales-officer/alerts',
            ],
            'dispatch_officer' => [
                '/dispatch',
                '/dispatch/fuel-lifting',
                '/dispatch/fuel-lifting/hauled',
                '/dispatch/ledger',
                '/dispatch/alerts',
            ],
            'driver' => [
                '/driver',
                '/driver/assigned-deliveries',
                '/driver/assigned-deliveries/completed',
                '/driver/fuel-lifting',
                '/driver/fuel-lifting/hauled',
                '/driver/fuel-lifting/no-schedule',
                '/driver/fuel-lifting/no-hauled',
            ],
        ];
    }

    public function test_guest_is_redirected_from_protected_pages_to_login(): void
    {
        $this->get('/admin/dashboard')
            ->assertRedirect('/login');

        $this->get('/dashboard')
            ->assertRedirect('/login');
    }

    #[DataProvider('protectedUrlProvider')]
    public function test_guest_is_redirected_from_every_protected_url_to_login(string $url): void
    {
        $this->get($url)
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

    public function test_role_shortcut_urls_are_also_role_protected(): void
    {
        $driver = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($driver)
            ->get('/admin')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect('/admin/dashboard');
    }

    /**
     * @param array<int, string> $allowedUrls
     * @param array<int, string> $blockedUrls
     */
    #[DataProvider('fullRoleAccessProvider')]
    public function test_every_role_route_is_enforced_server_side(string $role, array $allowedUrls, array $blockedUrls): void
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
        ]);

        foreach ($allowedUrls as $allowedUrl) {
            $response = $this->actingAs($user)->get($allowedUrl);

            $this->assertContains($response->getStatusCode(), [200, 302], "{$role} should access {$allowedUrl}");
        }

        foreach ($blockedUrls as $blockedUrl) {
            $this->actingAs($user)
                ->get($blockedUrl)
                ->assertForbidden();
        }
    }

    /**
     * @param array<int, string> $allowedUrls
     * @param array<int, string> $blockedUrls
     */
    #[DataProvider('fullRoleAccessProvider')]
    public function test_query_parameters_cannot_tamper_with_role_authorization(string $role, array $allowedUrls, array $blockedUrls): void
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
        ]);

        $this->assertContains(
            $this->actingAs($user)->get($allowedUrls[0].'?role=admin&redirect=/admin/dashboard')->getStatusCode(),
            [200, 302],
            'Allowed route should remain allowed regardless of query parameters.'
        );

        $this->actingAs($user)
            ->get($blockedUrls[0].'?role='.$role.'&redirect='.$allowedUrls[0])
            ->assertForbidden();
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

    public function test_login_regenerates_the_session_id(): void
    {
        $user = User::factory()->create([
            'email' => 'session@example.com',
            'role' => 'admin',
            'status' => 'active',
            'password' => 'password',
        ]);

        $this->startSession();
        $previousSessionId = session()->getId();

        $this->post('/login', [
            'username' => $user->email,
            'role' => 'admin',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertNotSame($previousSessionId, session()->getId());
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

    public function test_login_accepts_the_registered_account_name_as_username(): void
    {
        $user = User::factory()->create([
            'name' => 'luke',
            'email' => 'luke-login@example.com',
            'role' => 'admin',
            'status' => 'active',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'username' => 'luke',
            'role' => 'admin',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_still_accepts_the_registered_email_as_username(): void
    {
        $user = User::factory()->create([
            'name' => 'Email Login User',
            'email' => 'email-login@example.com',
            'role' => 'driver',
            'status' => 'active',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'username' => 'email-login@example.com',
            'role' => 'driver',
            'password' => 'password',
        ])->assertRedirect(route('driver.fuel-lifting'));

        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('roleDashboardProvider')]
    public function test_login_redirects_each_role_to_its_existing_dashboard(string $role, string $dashboardRoute): void
    {
        $user = User::factory()->create([
            'email' => "{$role}@example.com",
            'role' => $role,
            'status' => 'active',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'username' => $user->email,
            'role' => $role,
            'password' => 'password',
        ])->assertRedirect(route($dashboardRoute));
    }

    #[DataProvider('roleDashboardProvider')]
    public function test_generic_dashboard_routes_redirect_by_authenticated_database_role(string $role, string $dashboardRoute): void
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route($dashboardRoute));

        $this->actingAs($user)
            ->get('/home')
            ->assertRedirect(route($dashboardRoute));
    }

    public function test_registration_creates_user_in_database_and_requires_login(): void
    {
        $this->post('/register', [
            'full_name' => 'New Inventory User',
            'email' => 'new-inventory@example.com',
            'contact_number' => '09171234567',
            'role' => 'inventory_officer',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', [
            'name' => 'New Inventory User',
            'email' => 'new-inventory@example.com',
            'phone' => '09171234567',
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);

        $this->assertGuest();
    }

    public function test_dashboard_profile_uses_authenticated_account_information(): void
    {
        $user = User::factory()->create([
            'name' => 'Maria Santos',
            'email' => 'maria@example.com',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('Maria Santos')
            ->assertSee('MARIA SANTOS')
            ->assertSee('Admin')
            ->assertDontSee('CJ Pilar');
    }

    public function test_admin_dashboard_uses_safe_empty_states_without_demo_values(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('0 KL')
            ->assertSee('PHP 0')
            ->assertSee('No data available')
            ->assertDontSee('PHP 4,580,000')
            ->assertDontSee('PHP10.5M')
            ->assertDontSee('40,000 L');
    }

    public function test_admin_dashboard_calculates_values_from_existing_database_tables(): void
    {
        Carbon::setTestNow('2026-08-26 10:00:00');

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $driver = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
        ]);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-TEST',
            'name' => 'Test Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storageLocationId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-TEST',
            'name' => 'Test Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $premiumId = DB::table('fuel_types')->insertGetId([
            'code' => 'PREM',
            'name' => 'Premium',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dieselId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL',
            'name' => 'Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-TEST',
            'name' => 'Test Customer',
            'company_name' => 'Test Customer Company',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-TEST',
            'capacity_liters' => 10000,
            'truck_type' => 'delivery',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-TEST',
            'depot_id' => $depotId,
            'purchase_date' => now()->toDateString(),
            'payment_status' => 'partial',
            'status' => 'partially_hauled',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_items')->insert([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $premiumId,
            'quantity_ordered_liters' => 100000,
            'unit_cost' => 45,
            'line_total' => 4500000,
            'quantity_hauled_liters' => 40000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            [
                'movement_code' => 'MOV-IN-PREM',
                'storage_location_id' => $storageLocationId,
                'fuel_type_id' => $premiumId,
                'movement_type' => 'stock_in',
                'direction' => 'in',
                'quantity_liters' => 50000,
                'reference_type' => 'test',
                'reference_id' => 1,
                'movement_date' => now(),
                'created_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'movement_code' => 'MOV-OUT-PREM',
                'storage_location_id' => $storageLocationId,
                'fuel_type_id' => $premiumId,
                'movement_type' => 'stock_out',
                'direction' => 'out',
                'quantity_liters' => 10000,
                'reference_type' => 'test',
                'reference_id' => 2,
                'movement_date' => now(),
                'created_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'movement_code' => 'MOV-IN-DSL',
                'storage_location_id' => $storageLocationId,
                'fuel_type_id' => $dieselId,
                'movement_type' => 'beginning',
                'direction' => 'in',
                'quantity_liters' => 1000,
                'reference_type' => 'test',
                'reference_id' => 3,
                'movement_date' => now(),
                'created_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SAL-TEST',
            'customer_id' => $customerId,
            'sale_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'installment',
            'status' => 'partially_paid',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $premiumId,
            'quantity_liters' => 20000,
            'unit_price' => 90,
            'line_total' => 1800000,
            'fulfilled_quantity_liters' => 5000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'payment_code' => 'PAY-TEST',
            'sale_id' => $saleId,
            'payment_date' => now()->toDateString(),
            'amount' => 800000,
            'method' => 'bank_transfer',
            'received_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deliveries')->insert([
            'delivery_code' => 'DEL-TEST',
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $customerId,
            'fuel_type_id' => $premiumId,
            'source_type' => 'garage',
            'storage_location_id' => $storageLocationId,
            'truck_id' => $truckId,
            'driver_user_id' => $driver->id,
            'scheduled_at' => now(),
            'scheduled_quantity_liters' => 20000,
            'status' => 'in_transit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('41 KL')
            ->assertSee('PHP 1,800,000')
            ->assertSee('PHP 1,000,000')
            ->assertSee('60 KL')
            ->assertSee('Scheduled / in transit')
            ->assertSee('40,000 L')
            ->assertSee('Premium')
            ->assertSee('1,000 L')
            ->assertSee('Diesel')
            ->assertSee('PHP1,800,000')
            ->assertSee('PHP800,000')
            ->assertSee('PHP1,000,000')
            ->assertSee('Hot Wed');

        Carbon::setTestNow();
    }

    public function test_registration_validates_unique_email_role_and_confirmed_password(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $this->post('/register', [
            'full_name' => 'Duplicate User',
            'email' => 'existing@example.com',
            'contact_number' => '09171234567',
            'role' => 'unknown_role',
            'password' => 'password',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['email', 'role', 'password']);

        $this->assertGuest();
    }

    public function test_forgot_password_sends_code_to_registered_active_account_email(): void
    {
        Mail::fake();

        User::factory()->create([
            'name' => 'Reset User',
            'email' => 'reset@example.com',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $sentCode = null;

        $this->post('/forgot-password', [
            'email' => 'reset@example.com',
        ])->assertRedirect(route('password.reset'));

        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$sentCode): bool {
            $sentCode = $mail->code;

            return $mail->hasTo('reset@example.com') && preg_match('/^\d{6}$/', $mail->code) === 1;
        });

        $reset = DB::table('password_reset_tokens')
            ->where('email', 'reset@example.com')
            ->first();

        $this->assertNotNull($reset);
        $this->assertNotSame($sentCode, $reset->token);
        $this->assertTrue(Hash::check($sentCode, $reset->token));
    }

    public function test_forgot_password_does_not_send_code_for_unknown_email(): void
    {
        Mail::fake();

        $this->post('/forgot-password', [
            'email' => 'unknown@example.com',
        ])->assertRedirect(route('password.reset'));

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'unknown@example.com',
        ]);
    }

    public function test_forgot_password_returns_validation_error_when_mail_fails(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->with('mail-fail@example.com')
            ->andThrow(new TransportException('SMTP authentication failed.'));

        User::factory()->create([
            'email' => 'mail-fail@example.com',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->from('/forgot-password')
            ->post('/forgot-password', [
                'email' => 'mail-fail@example.com',
            ])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'mail-fail@example.com',
        ]);
    }

    public function test_password_can_be_reset_with_valid_email_code_and_new_password(): void
    {
        $user = User::factory()->create([
            'email' => 'change-password@example.com',
            'role' => 'driver',
            'status' => 'active',
            'password' => 'old-password',
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->post('/reset-password', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $user->email,
        ]);

        $this->post('/login', [
            'username' => $user->email,
            'role' => 'driver',
            'password' => 'new-password',
        ])->assertRedirect(route('driver.fuel-lifting'));
    }

    public function test_password_reset_rejects_invalid_or_expired_code(): void
    {
        $user = User::factory()->create([
            'email' => 'expired-code@example.com',
            'role' => 'admin',
            'status' => 'active',
            'password' => 'old-password',
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('123456'),
            'created_at' => now()->subMinutes(16),
        ]);

        $this->post('/reset-password', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
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

    public function test_protected_routes_remain_blocked_after_logout(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/admin/dashboard')
            ->assertOk();

        $this->post('/logout')
            ->assertRedirect('/login');

        $this->get('/admin/dashboard')
            ->assertRedirect('/login');
    }

    public function test_switching_accounts_does_not_retain_previous_role_access(): void
    {
        $admin = User::factory()->create([
            'email' => 'switch-admin@example.com',
            'role' => 'admin',
            'status' => 'active',
            'password' => 'password',
        ]);

        $driver = User::factory()->create([
            'email' => 'switch-driver@example.com',
            'role' => 'driver',
            'status' => 'active',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'username' => $admin->email,
            'role' => 'admin',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->get('/admin/dashboard')
            ->assertOk();

        $this->post('/logout')
            ->assertRedirect('/login');

        $this->post('/login', [
            'username' => $driver->email,
            'role' => 'driver',
            'password' => 'password',
        ])->assertRedirect(route('driver.fuel-lifting'));

        $this->get('/admin/dashboard')
            ->assertForbidden();

        $this->get('/driver/fuel-lifting')
            ->assertOk();
    }
}
