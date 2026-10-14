<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModuleActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PART 6 (October 12, 2026) System Analysis revisions.
 */
class Part6RevisionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_eye_toggle_is_a_real_button_inside_the_password_field(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<div class="password-input-wrap">\s*<input id="password"[^>]*type="password"[^>]*>\s*<button type="button" class="password-toggle-btn" aria-label="Show password"[^>]*data-password-toggle="password">/',
            $html
        );

        $styles = File::get(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.password-input-wrap \{[^}]*position: relative;/', $styles);
        $this->assertMatchesRegularExpression('/\.password-toggle-btn \{[^}]*position: absolute;[^}]*right: 20px;[^}]*z-index: 2;/', $styles);
        // The input reserves room on the right so typed text never runs under the icon.
        $this->assertMatchesRegularExpression('/\.login-field input,\n\.login-field select \{[^}]*padding: 0 76px;/', $styles);
    }

    public function test_purchases_table_hides_allocation_columns_and_shows_receipt_column(): void
    {
        $records = $this->records();
        $withoutReceipt = $this->purchaseItem($records, 'PUR-P6-NONE');
        $withReceipt = $this->purchaseItem($records, 'PUR-P6-ONE');
        $haulId = $this->haul($records, $withReceipt, 'LFT-P6-ONE', ['withdrawal_receipt_path' => 'withdrawal-receipts/p6.png', 'withdrawal_receipt_uploaded_at' => now()]);

        $page = $this->actingAs($records['inventory'])->get(route('inventory-officer.inventory'))->assertOk();
        $purchases = $this->panel($page->getContent(), 'purchases');

        $this->assertStringNotContainsString('<th>Garage Allocation</th>', $purchases);
        $this->assertStringNotContainsString('<th>Direct Allocation</th>', $purchases);
        $this->assertStringNotContainsString('Withdrawals</th>', $purchases);
        $this->assertStringContainsString('<th>Receipt</th>', $purchases);
        $this->assertStringContainsString('aria-label="No receipt uploaded">-</span>', $purchases);
        $this->assertStringContainsString('href="'.route('withdrawal-receipts.show', $haulId).'"', $purchases);
        // Allocation data is kept: it is still shown in the purchase details.
        $page->assertSee('Direct Client Allocation');
        $this->assertDatabaseHas('purchase_items', ['id' => $withoutReceipt]);

        // "+ Record Purchase" is the white (outlined) wide button and "+ Depot" lives only on the Depots tab.
        $this->assertStringContainsString('<button class="btn btn-secondary btn-wide" type="button" data-modal-open="io-purchase-add">+ Record Purchase</button>', $purchases);
        $this->assertStringNotContainsString('+ Depot', $purchases);
        $this->assertStringContainsString('+ Depot', $this->panel($page->getContent(), 'depots'));
    }

    public function test_purchase_with_several_receipts_lists_each_one(): void
    {
        $records = $this->records();
        $item = $this->purchaseItem($records, 'PUR-P6-MANY');
        $first = $this->haul($records, $item, 'LFT-P6-A', ['withdrawal_receipt_path' => 'withdrawal-receipts/a.png', 'withdrawal_receipt_uploaded_at' => now()]);
        $second = $this->haul($records, $item, 'LFT-P6-B', ['withdrawal_receipt_path' => 'withdrawal-receipts/b.png', 'withdrawal_receipt_uploaded_at' => now()]);

        $this->actingAs($records['admin'])->get(route('admin.inventory'))
            ->assertOk()
            ->assertSee('data-modal-open="io-purchase-edit-'.$item.'-receipts">2 receipts</button>', false)
            ->assertSee('id="io-purchase-edit-'.$item.'-receipts"', false)
            ->assertSee(route('withdrawal-receipts.show', $first), false)
            ->assertSee(route('withdrawal-receipts.show', $second), false);
    }

    public function test_filtering_keeps_the_active_tab_and_hides_the_others(): void
    {
        $records = $this->records();

        $html = $this->actingAs($records['inventory'])
            ->get(route('inventory-officer.inventory.stock-out', ['status' => 'released']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<section data-tab-panel="stock-out"\s*>/', $html);
        foreach (['purchases', 'stock-in', 'depots'] as $tab) {
            $this->assertMatchesRegularExpression('/<section data-tab-panel="'.$tab.'"\s+hidden\s*>/', $html);
        }
        // Tab buttons carry their URL so a reload, filter or modal round-trip returns to the same tab.
        $this->assertStringContainsString('data-tab-target="stock-out" data-tab-url="'.route('inventory-officer.inventory.stock-out').'"', $html);
        $this->assertMatchesRegularExpression('/\[data-tab-panel\]\[hidden\] \{\s*display: none !important;/', File::get(resource_path('css/app.css')));

        foreach ([
            [$records['admin'], route('admin.sales.customers', ['status' => 'active']), 'customers', ['receivables']],
            [$records['admin'], route('admin.ledger.transactions', ['status' => 'complete']), 'transactions', ['ledger']],
            [$records['dispatch'], route('dispatch.fuel-lifting.hauled', ['status' => 'completed']), 'hauled', ['schedule', 'trucks']],
        ] as [$user, $url, $active, $hidden]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<section data-tab-panel="'.$active.'"\s*>/', $html, $url);
            foreach ($hidden as $tab) {
                $this->assertMatchesRegularExpression('/<section data-tab-panel="'.$tab.'"\s+hidden\s*>/', $html, $url);
            }
        }
    }

    public function test_trucks_is_a_fuel_lifting_tab_not_a_sidebar_link(): void
    {
        $records = $this->records();

        foreach ([[$records['admin'], 'admin'], [$records['dispatch'], 'dispatch']] as [$user, $prefix]) {
            $html = $this->actingAs($user)->get(route($prefix.'.fuel-lifting.trucks', ['status' => 'available']))->assertOk()->getContent();

            $this->assertStringNotContainsString('>Trucks</span>', $this->between($html, '<nav class="side-nav', '</nav>'));
            $this->assertStringContainsString('data-tab-target="trucks"', $html);
            $this->assertMatchesRegularExpression('/<section data-tab-panel="trucks"\s*>/', $html);
            $trucks = $this->panel($html, 'trucks');
            $this->assertStringContainsString('TRK-P6', $trucks);
            $this->assertStringContainsString('<select name="status" aria-label="Filter by status"', $trucks);
            $this->assertStringNotContainsString('>Filter</button>', $trucks);
            // "+ Add Truck" sits at the end of the filter bar, white like the other "+" buttons.
            $this->assertMatchesRegularExpression('/<\/select>\s*<button class="btn btn-secondary btn-wide" type="button" data-modal-open="truck-add">\+ Add Truck<\/button>\s*<\/form>/', $trucks);
        }
    }

    public function test_no_filter_submit_buttons_or_placeholder_filter_buttons_remain_in_views(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $contents = $file->getContents();
            if (preg_match_all('/<button[^>]*>\s*(Filter|Apply|Search|Date|Status|Depot|Location|Company|Source|Destination|Driver|Truck|Position|Contact|License|Fuel Type[^<]*)\s*<\/button>/', $contents, $matches)) {
                $offenders[] = $file->getRelativePathname().': '.implode(', ', $matches[1]);
            }
            if (str_contains($contents, 'data-sort-table')) {
                $offenders[] = $file->getRelativePathname().': data-sort-table';
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_ledger_filters_and_centered_layout_for_every_role(): void
    {
        $records = $this->records();
        $otherDepot = DB::table('depots')->insertGetId(['depot_code' => 'DEP-P6-N', 'name' => 'Northern Terminal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->purchaseItem($records, 'PUR-P6-SOUTH');
        $this->purchaseItem($records, 'PUR-P6-NORTH', $otherDepot);

        foreach ([[$records['admin'], 'admin.ledger'], [$records['inventory'], 'inventory-officer.ledger'], [$records['dispatch'], 'dispatch.ledger']] as [$user, $route]) {
            $page = $this->actingAs($user)->get(route($route, ['depot_id' => $otherDepot]))->assertOk();
            $this->assertSame(2, substr_count($page->getContent(), 'class="admin-table ledger-table"'), $route);
            $page->assertSee('PUR-P6-NORTH')->assertDontSee('PUR-P6-SOUTH');
        }

        $this->actingAs($records['admin'])->get(route('admin.ledger.transactions', ['status' => 'incomplete']))
            ->assertOk()->assertSee('PUR-P6-SOUTH');
        $this->actingAs($records['admin'])->get(route('admin.ledger.transactions', ['status' => 'complete']))
            ->assertOk()->assertDontSee('PUR-P6-SOUTH');
    }

    public function test_sales_and_alert_dropdown_filters_apply(): void
    {
        $records = $this->records();
        $customer = DB::table('customers')->insertGetId(['customer_code' => 'CUS-P6', 'name' => 'P6 Customer', 'company_name' => 'P6 Co', 'location' => 'Subic', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->insert(['customer_code' => 'CUS-P6-OFF', 'name' => 'Dormant Buyer', 'company_name' => 'Dormant Co', 'location' => 'Olongapo', 'payment_status' => 'clear', 'status' => 'inactive', 'created_at' => now(), 'updated_at' => now()]);
        $paid = $this->sale($records, $customer, 'SLS-P6-PAID', 1000);
        $this->sale($records, $customer, 'SLS-P6-OPEN', 0);

        $this->actingAs($records['sales'])->get(route('sales-officer.sales', ['status' => 'clear']))
            ->assertOk()->assertSee('SLS-P6-PAID')->assertDontSee('SLS-P6-OPEN');
        $this->actingAs($records['admin'])->get(route('admin.sales', ['status' => 'unpaid']))
            ->assertOk()->assertSee('SLS-P6-OPEN')->assertDontSee('SLS-P6-PAID');
        $customers = $this->panel($this->actingAs($records['sales'])->get(route('sales-officer.sales.customers', ['location' => 'Olongapo']))->assertOk()->getContent(), 'customers');
        $this->assertStringContainsString('Dormant Buyer', $customers);
        $this->assertStringNotContainsString('P6 Customer', $customers);
        $this->assertDatabaseHas('payments', ['sale_id' => $paid]);

        foreach (['critical', 'warning'] as $severity) {
            DB::table('alerts')->insert(['alert_code' => 'ALT-P6-'.$severity, 'type' => 'payment', 'severity' => $severity, 'title' => 'P6 '.$severity.' alert', 'message' => 'Check', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($records['sales'])->get(route('sales-officer.alerts', ['severity' => 'critical']))
            ->assertOk()->assertSee('P6 critical alert')->assertDontSee('P6 warning alert')
            ->assertSee('<select name="read" aria-label="Filter by status"', false);
    }

    public function test_sidebar_red_dot_shows_unseen_changes_and_clears_when_the_module_is_opened(): void
    {
        $records = $this->records();
        $this->travelTo(now()->setTime(9, 0));

        // Opening Inventory records the visit and clears its dot on that same page.
        $this->actingAs($records['inventory'])->get(route('inventory-officer.inventory'))->assertOk()
            ->assertDontSee('data-nav-dot="inventory"', false);

        $this->travel(5)->minutes();
        $this->purchaseItem($records, 'PUR-P6-NEW');

        // Another module page shows the dot on Inventory (new purchase not seen yet).
        $this->actingAs($records['inventory'])->get(route('inventory-officer.alerts'))->assertOk()
            ->assertSee('data-nav-dot="inventory"', false);
        $this->assertContains('inventory', app(ModuleActivityService::class)->unseenModules($records['inventory']));

        $this->travel(1)->minutes();
        $this->actingAs($records['inventory'])->get(route('inventory-officer.inventory.stock-in'))->assertOk();
        $this->actingAs($records['inventory'])->get(route('inventory-officer.alerts'))->assertOk()
            ->assertDontSee('data-nav-dot="inventory"', false);

        // Only modules the role can open are tracked: a sales officer never gets an Inventory dot.
        $this->app->forgetScopedInstances();
        $this->assertNotContains('inventory', app(ModuleActivityService::class)->unseenModules($records['sales']));
        $this->actingAs($records['sales'])->get(route('admin.inventory'))->assertForbidden();
        $this->assertDatabaseMissing('user_module_views', ['user_id' => $records['sales']->id, 'module' => 'inventory']);
    }

    public function test_driver_red_dot_only_counts_the_drivers_own_lifts(): void
    {
        $records = $this->records();
        $otherDriver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved']);
        $this->travelTo(now()->setTime(9, 0));
        $this->actingAs($records['driver'])->get(route('driver.fuel-lifting'))->assertOk();

        $this->travel(5)->minutes();
        $item = $this->purchaseItem($records, 'PUR-P6-DRV');
        $this->haul($records, $item, 'LFT-P6-OTHER', ['driver_user_id' => $otherDriver->id]);
        $this->assertSame([], app(ModuleActivityService::class)->unseenModules($records['driver']));

        $this->haul($records, $item, 'LFT-P6-MINE');
        $this->app->forgetScopedInstances(); // a new request gets a fresh lookup
        $this->assertSame(['fuel-lifting'], app(ModuleActivityService::class)->unseenModules($records['driver']->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $users = [];
        foreach (['admin' => 'admin', 'inventory' => 'inventory_officer', 'sales' => 'sales_officer', 'dispatch' => 'dispatch_officer', 'driver' => 'driver'] as $key => $role) {
            $users[$key] = User::factory()->create(['role' => $role, 'status' => 'active', 'approval_status' => 'approved']);
        }
        DB::table('driver_profiles')->insert(['user_id' => $users['driver']->id, 'driver_code' => 'DRV-P6', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

        return $users + [
            'depot' => DB::table('depots')->insertGetId(['depot_code' => 'DEP-P6', 'name' => 'Southern Terminal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]),
            'fuel' => (int) DB::table('fuel_types')->where('code', 'DSL')->value('id'),
            'truck' => DB::table('trucks')->insertGetId(['truck_code' => 'TRK-P6', 'plate_number' => 'P6-0001', 'name' => 'P6 Tanker', 'capacity_liters' => 30000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]),
        ];
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function purchaseItem(array $records, string $code, ?int $depotId = null): int
    {
        $purchase = DB::table('purchases')->insertGetId([
            'purchase_code' => $code, 'depot_id' => $depotId ?? $records['depot'], 'purchase_date' => '2026-10-12', 'payment_status' => 'unpaid',
            'status' => 'ordered', 'workflow_status' => 'pending', 'created_by' => $records['inventory']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchase, 'fuel_type_id' => $records['fuel'], 'quantity_ordered_liters' => 10000, 'unit_cost' => 50,
            'line_total' => 500000, 'quantity_hauled_liters' => 0, 'status' => 'unlifted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     * @param  array<string, mixed>  $overrides
     */
    private function haul(array $records, int $purchaseItemId, string $code, array $overrides = []): int
    {
        $item = DB::table('purchase_items')->where('id', $purchaseItemId)->first();

        return DB::table('hauls')->insertGetId(array_merge([
            'haul_code' => $code, 'purchase_id' => $item->purchase_id, 'purchase_item_id' => $purchaseItemId, 'depot_id' => $records['depot'],
            'fuel_type_id' => $records['fuel'], 'truck_id' => $records['truck'], 'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-10-12 08:00:00', 'quantity_liters' => 1000, 'status' => 'lifted', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function sale(array $records, int $customerId, string $code, float $paid): int
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $code, 'customer_id' => $customerId, 'sale_date' => '2026-10-12', 'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod', 'status' => 'confirmed', 'delivery_status' => 'pending', 'created_by' => $records['sales']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $saleId, 'fuel_type_id' => $records['fuel'], 'quantity_liters' => 100, 'unit_price' => 10, 'line_total' => 1000,
            'fulfilled_quantity_liters' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($paid > 0) {
            DB::table('payments')->insert([
                'payment_code' => 'PAY-'.$code, 'sale_id' => $saleId, 'payment_date' => '2026-10-12', 'amount' => $paid,
                'method' => 'cash_on_delivery', 'received_by' => $records['sales']->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $saleId;
    }

    private function panel(string $html, string $tab): string
    {
        return $this->between($html, '<section data-tab-panel="'.$tab.'"', '</section>');
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, 'Missing '.$start);

        return substr($html, $from, strpos($html, $end, $from) - $from);
    }
}
