<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_guest_is_redirected_from_protected_pages_to_login(): void
    {
        $this->get('/admin/dashboard')
            ->assertRedirect('/login');

        $this->get('/dashboard')
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
}
