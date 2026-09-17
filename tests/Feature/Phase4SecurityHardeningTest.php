<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase4SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_timezone_is_asia_manila(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
    }

    public function test_security_headers_are_present_on_web_responses(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_strict_transport_security_header_present_when_secure(): void
    {
        $response = $this->get('https://cjp-southern-star.example.com/login');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_public_registration_rejects_admin_role(): void
    {
        $response = $this->from('/register')->post('/register', [
            'full_name' => 'Attacker Admin',
            'email' => 'attacker@example.com',
            'contact_number' => '09171234567',
            'role' => 'admin',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
    }

    public function test_public_registration_defaults_to_driver_when_role_omitted(): void
    {
        $response = $this->post('/register', [
            'full_name' => 'New Driver Candidate',
            'email' => 'candidate@example.com',
            'contact_number' => '09171234567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertRedirect('/login');
        $this->assertDatabaseHas('users', [
            'email' => 'candidate@example.com',
            'role' => 'driver',
            'approval_status' => 'pending',
            'status' => 'active',
        ]);
    }

    public function test_public_registration_allows_operational_roles(): void
    {
        $response = $this->post('/register', [
            'full_name' => 'New Dispatch Staff',
            'email' => 'dispatch.staff@example.com',
            'contact_number' => '09171234567',
            'role' => 'dispatch_officer',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertRedirect('/login');
        $this->assertDatabaseHas('users', [
            'email' => 'dispatch.staff@example.com',
            'role' => 'dispatch_officer',
            'approval_status' => 'pending',
        ]);
    }

    public function test_login_rejects_ambiguous_names_and_requires_unique_email(): void
    {
        // Two active, approved users with identical full name
        $user1 = User::factory()->create([
            'name' => 'Duplicate Name',
            'email' => 'unique1@example.com',
            'password' => Hash::make('Password123!'),
            'role' => 'driver',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);

        $user2 = User::factory()->create([
            'name' => 'Duplicate Name',
            'email' => 'unique2@example.com',
            'password' => Hash::make('Password123!'),
            'role' => 'driver',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);

        // Attempt login with ambiguous full name
        $response = $this->from('/login')->post('/login', [
            'username' => 'Duplicate Name',
            'password' => 'Password123!',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['username']);
        $this->assertGuest();

        // Successful login with unique email
        $successResponse = $this->post('/login', [
            'username' => 'unique1@example.com',
            'password' => 'Password123!',
        ]);

        $successResponse->assertRedirect(route('driver.fuel-lifting'));
        $this->assertAuthenticatedAs($user1);
    }

    public function test_csv_export_in_admin_sales_report_neutralizes_formula_triggers(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);

        $customer = DB::table('customers')->insertGetId([
            'customer_code' => 'CSM-000099',
            'name' => '=HYPERLINK("http://evil.com","Click")',
            'company_name' => '@SUM(1+1)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id')
            ?? DB::table('fuel_types')->insertGetId([
                'name' => 'DIESEL',
                'code' => 'DSL',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => '+SLS-FORMULA-001',
            'sales_order_number' => '-SO-999',
            'customer_id' => $customer,
            'sale_date' => now()->toDateString(),
            'status' => 'confirmed',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_liters' => 100,
            'fulfilled_quantity_liters' => 100,
            'unit_price' => 50.00,
            'line_total' => 5000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.reports.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->getContent();

        // Formula prefixes must be preceded by a single quote
        $this->assertStringContainsString("'+SLS-FORMULA-001", $content);
        $this->assertStringContainsString("'-SO-999", $content);
        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringContainsString("'@SUM", $content);
    }

    public function test_csv_export_in_user_management_neutralizes_formula_triggers(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);

        DB::table('customers')->insert([
            'customer_code' => 'CSM-000098',
            'name' => '+InjectedCustomer',
            'company_name' => '=1337',
            'location' => '@ManilaOffice',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin/user-management/export?tab=customers');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->getContent();

        $this->assertStringContainsString("'+InjectedCustomer", $content);
        $this->assertStringContainsString("'=1337", $content);
        $this->assertStringContainsString("'@ManilaOffice", $content);
    }

    public function test_withdrawal_receipt_authorization_enforced(): void
    {
        Storage::fake('local');
        $fakePath = 'withdrawal-receipts/sample-receipt.png';
        Storage::disk('local')->put($fakePath, 'fake-image-bytes');

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DPT-01',
            'name' => 'Test Depot',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id')
            ?? DB::table('fuel_types')->insertGetId([
                'name' => 'DIESEL',
                'code' => 'DSL',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-01',
            'plate_number' => 'ABC-1234',
            'capacity_liters' => 10000,
            'truck_type' => 'delivery',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driverUser = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PO-RECEIPT-001',
            'depot_id' => $depotId,
            'purchase_date' => now()->toDateString(),
            'status' => 'hauled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_ordered_liters' => 5000,
            'unit_cost' => 50.00,
            'line_total' => 250000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'HL-RECEIPT-001',
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $depotId,
            'fuel_type_id' => $fuelTypeId,
            'truck_id' => $truckId,
            'driver_user_id' => $driverUser->id,
            'quantity_liters' => 5000,
            'scheduled_at' => now(),
            'status' => 'completed',
            'withdrawal_receipt_path' => $fakePath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Guest request -> redirected to login
        $guestResponse = $this->get('/withdrawal-receipts/'.$haulId);
        $guestResponse->assertRedirect('/login');

        // 2. Unauthorized roles (driver, sales_officer) -> 403 Forbidden
        $driver = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);
        $driverResponse = $this->actingAs($driver)->get('/withdrawal-receipts/'.$haulId);
        $driverResponse->assertForbidden();

        $salesOfficer = User::factory()->create([
            'role' => 'sales_officer',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);
        $salesOfficerResponse = $this->actingAs($salesOfficer)->get('/withdrawal-receipts/'.$haulId);
        $salesOfficerResponse->assertForbidden();

        // 3. Authorized roles (admin, inventory_officer) -> 200 OK
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);
        $adminResponse = $this->actingAs($admin)->get('/withdrawal-receipts/'.$haulId);
        $adminResponse->assertOk();
        $this->assertSame('fake-image-bytes', $adminResponse->getContent());

        $inventoryOfficer = User::factory()->create([
            'role' => 'inventory_officer',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);
        $ioResponse = $this->actingAs($inventoryOfficer)->get('/withdrawal-receipts/'.$haulId);
        $ioResponse->assertOk();
        $this->assertSame('fake-image-bytes', $ioResponse->getContent());
    }
}
