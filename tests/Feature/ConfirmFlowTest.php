<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Confirmation flow (UI only): every page carries one confirm dialog, and each data-changing form says how it is
 * confirmed (review / delete / status / simple / none) and names the exact record. The dialog behaviour itself
 * (review summary, Old -> New, typed ID, Esc / Back, spinner) is covered by tests/js/confirm.test.mjs.
 */
class ConfirmFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function records(): array
    {
        $make = fn (string $name, string $role, string $approval = 'approved') => User::factory()->create(['name' => $name, 'role' => $role, 'status' => 'active', 'approval_status' => $approval]);
        $admin = $make('Maria Santos', 'admin');
        $driver = $make('Manuel Ligaya', 'driver');
        DB::table('driver_profiles')->insert(['user_id' => $driver->id, 'driver_code' => 'DRV-0001', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $fuel = (int) DB::table('fuel_types')->where('code', 'UNL')->value('id');
        $depot = DB::table('depots')->insertGetId(['depot_code' => 'DEP-BAT', 'name' => 'Batangas Energy Terminal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $truck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-001', 'plate_number' => 'ABC 1234', 'name' => 'Isuzu Giga', 'capacity_liters' => 20000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->post(route('admin.inventory.purchases.store'), [
            'idempotency_key' => (string) Str::uuid(), 'purchase_date' => '2026-10-10', 'depot_id' => $depot, 'fuel_type_id' => $fuel,
            'quantity_ordered_liters' => 120000, 'unit_cost' => 50, 'payment_status' => 'unpaid',
        ])->assertSessionHasNoErrors();

        return [
            'admin' => $admin,
            'driver' => $driver,
            'inventory' => $make('Ivan Ocampo', 'inventory_officer'),
            'sales' => $make('Sofia Reyes', 'sales_officer'),
            'dispatch' => $make('Diego Cruz', 'dispatch_officer'),
            'pending' => $make('Pending Person', 'sales_officer', 'pending'),
            'truck' => $truck,
            'purchaseCode' => (string) DB::table('purchases')->value('purchase_code'),
            'itemId' => (int) DB::table('purchase_items')->value('id'),
        ];
    }

    public function test_every_role_page_has_exactly_one_confirm_dialog_and_no_old_generic_modal(): void
    {
        $r = $this->records();

        foreach ([
            [$r['admin'], route('admin.dashboard')],
            [$r['admin'], route('admin.user-management')],
            [$r['inventory'], route('inventory-officer.inventory')],
            [$r['inventory'], route('inventory-officer.ledger')],
            [$r['sales'], route('sales-officer.sales')],
            [$r['dispatch'], route('dispatch.fuel-lifting')],
            [$r['driver'], route('driver.fuel-lifting')],
        ] as [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'data-confirm-dialog'), $url);
            $this->assertStringContainsString('data-cd-ok', $html, $url);
            $this->assertStringContainsString('data-cd-typed-input', $html, $url);
            $this->assertStringNotContainsString('system-confirm-modal', $html, $url);
        }
    }

    public function test_the_confirm_script_intercepts_submits_and_uses_no_browser_confirm(): void
    {
        $js = File::get(resource_path('js/confirm.js'));

        $this->assertStringContainsString("document.addEventListener('submit', onSubmit, true)", $js);
        $this->assertStringNotContainsString('window.confirm(', $js);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])confirm\(/', $js);
        $this->assertStringContainsString("import './confirm';", File::get(resource_path('js/responsive.js')));
    }

    public function test_cancel_purchase_names_the_purchase_and_requires_its_id_typed(): void
    {
        $r = $this->records();
        $code = $r['purchaseCode'];

        $html = $this->actingAs($r['admin'])->get(route('admin.inventory'))->assertOk()->getContent();

        $this->assertStringContainsString('data-confirm="delete" data-confirm-action="Cancel purchase" data-confirm-typed="'.$code.'"', $html);
        $this->assertMatchesRegularExpression('/data-confirm-item="'.preg_quote($code, '/').' \(Unleaded, 120,000(\.00)? L, Batangas Energy Terminal\)"/', $html);
        $this->assertStringContainsString('data-confirm-undo="This can\'t be undone. The purchase is marked Cancelled', $html);
        // Edit Purchase reviews the changed fields of this purchase.
        $this->assertMatchesRegularExpression('/<form id="purchase-update-\d+" data-confirm-item="'.preg_quote($code, '/').'"/', $html);
    }

    public function test_delete_lift_names_the_lift_and_requires_its_id_typed(): void
    {
        $r = $this->records();
        $this->actingAs($r['admin'])->post(route('admin.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'schedule-lift',
            'items' => [['purchase_item_id' => $r['itemId'], 'quantity_liters' => 15000]],
            'driver_user_id' => $r['driver']->id, 'truck_id' => $r['truck'], 'scheduled_at' => '2026-11-09 08:00:00',
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($r['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-confirm="delete" data-confirm-action="Delete lift" data-confirm-typed="LFT-[A-Z0-9-]+"/', $html);
        $this->assertMatchesRegularExpression('/data-confirm-item="LFT-[A-Z0-9-]+ \('.preg_quote($r['purchaseCode'], '/').', 15,000(\.00)? L, Batangas Energy Terminal/', $html);
        // The older one-line message is kept (other tests and the server rely on it).
        $this->assertStringContainsString('data-confirm-message="Delete lift', $html);
    }

    public function test_account_requests_approve_is_a_status_change_and_reject_is_destructive(): void
    {
        $r = $this->records();
        $html = $this->actingAs($r['admin'])->get(route('admin.user-management', ['tab' => 'account-requests']))->assertOk()->getContent();
        $item = 'Pending Person (Sales Officer, '.$r['pending']->email.')';

        $this->assertStringContainsString('data-confirm="status" data-confirm-title="Approve account request?" data-confirm-item="'.$item.'"', $html);
        $this->assertStringContainsString('data-confirm-from="Pending" data-confirm-to="Approved"', $html);
        $this->assertStringContainsString('data-confirm="delete" data-confirm-action="Reject account request" data-confirm-item="'.$item.'"', $html);
        $this->assertStringContainsString('data-confirm-message="Reject the account request of Pending Person?', $html);
    }

    public function test_deactivating_an_account_shows_from_and_to_status_in_red(): void
    {
        $r = $this->records();
        $html = $this->actingAs($r['admin'])->get(route('admin.user-management'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-confirm="status" data-confirm-title="Deactivate account\?"\s+data-confirm-item="EMP-\d{6} \(Ivan Ocampo\)" data-confirm-from="Active" data-confirm-to="Inactive"\s+data-confirm-tone="danger"/', $html);
    }

    public function test_truck_status_alert_read_and_sign_out_have_their_own_confirmations(): void
    {
        $r = $this->records();
        DB::table('alerts')->insert(['alert_code' => 'ALT-1', 'type' => 'inventory', 'severity' => 'warning', 'title' => 'Low stock: Unleaded', 'message' => 'x', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        $trucks = $this->actingAs($r['dispatch'])->get(route('dispatch.fuel-lifting.trucks'))->assertOk()->getContent();
        $this->assertStringContainsString('data-confirm="status" data-confirm-title="Deactivate truck?"', $trucks);
        $this->assertStringContainsString('data-confirm-item="TRK-001 · ABC 1234 (Isuzu Giga)"', $trucks);

        $alerts = $this->actingAs($r['admin'])->get(route('admin.alerts'))->assertOk()->getContent();
        $this->assertStringContainsString('data-confirm="status" data-confirm-title="Mark alert as read?" data-confirm-item="', $alerts);
        $this->assertStringContainsString('data-confirm-from="Unread" data-confirm-to="Read"', $alerts);
        $this->assertStringContainsString('data-confirm="simple" data-confirm-title="Sign out?" data-confirm-ok="Sign Out"', $alerts);
    }

    public function test_exports_and_report_generation_skip_confirmation(): void
    {
        $r = $this->records();
        $html = $this->actingAs($r['admin'])->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-dashboard-export data-confirm="none"', $html);
        $this->assertStringContainsString('data-ai-generate-form data-confirm="none"', $html);
    }

    public function test_confirmed_submissions_still_reach_the_server_and_flash_the_success_toast(): void
    {
        $r = $this->records();

        // The dialog only delays the normal post; the same request still saves and the toast shows after the redirect.
        $this->actingAs($r['admin'])->from(route('admin.inventory.depots'))->post(route('admin.inventory.depots.store'), [
            'depot_code' => 'DEP-NEW', 'name' => 'New Depot', 'status' => 'active',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->actingAs($r['admin'])->get(route('admin.inventory.depots'))->assertOk()->assertSee('cjp-toast', false);
    }
}
