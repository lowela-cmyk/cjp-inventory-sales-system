<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OperationalDataRepairService
{
    public function __construct(
        private readonly GarageTankService $garageTankService,
        private readonly SaleConfirmationService $saleConfirmationService
    ) {}

    /**
     * Perform the complete Phase 3 operational repair, master data provisioning,
     * and acceptance data reconciliation.
     *
     * @return array{
     *     legacy_repaired: bool,
     *     master_data: array<string, int>,
     *     acceptance_workflows: array<string, array<string, mixed>>,
     *     reconciliation: array<string, mixed>
     * }
     */
    public function run(): array
    {
        return DB::transaction(function (): array {
            $legacyRepaired = $this->repairLegacySale();
            $masterData = $this->seedMasterData();
            $acceptanceWorkflows = $this->seedOperationalAcceptanceData();
            $reconciliation = $this->reconcileSummary();

            return [
                'legacy_repaired' => $legacyRepaired,
                'master_data' => $masterData,
                'acceptance_workflows' => $acceptanceWorkflows,
                'reconciliation' => $reconciliation,
            ];
        });
    }

    /**
     * Repair the existing legacy 50-liter sale (SLS-000001) by fulfilling it with an
     * authoritative beginning balance, stock-out, and movement records.
     */
    public function repairLegacySale(): bool
    {
        $sale = DB::table('sales')->where('sale_code', 'SLS-000001')->first();
        if (! $sale) {
            return false;
        }

        $item = DB::table('sale_items')->where('sale_id', $sale->id)->first();
        if (! $item) {
            return false;
        }

        $dieselFuel = DB::table('fuel_types')->where('code', 'DSL')->first();
        if (! $dieselFuel) {
            return false;
        }

        // Map item to active approved Diesel if currently pointing to old inactive ADO
        if ((int) $item->fuel_type_id !== (int) $dieselFuel->id) {
            DB::table('sale_items')->where('id', $item->id)->update([
                'fuel_type_id' => $dieselFuel->id,
                'updated_at' => now(),
            ]);
        }

        // Resolve active Diesel garage tank 1
        $tank = DB::table('storage_locations')
            ->where('fuel_type_id', $dieselFuel->id)
            ->where('type', 'garage')
            ->where('status', 'active')
            ->orderBy('tank_number')
            ->first();

        if (! $tank) {
            $this->garageTankService->ensureForActiveFuelTypes();
            $tank = DB::table('storage_locations')
                ->where('fuel_type_id', $dieselFuel->id)
                ->where('type', 'garage')
                ->where('status', 'active')
                ->orderBy('tank_number')
                ->first();
        }

        $inventoryOfficerId = DB::table('users')->where('role', 'inventory_officer')->value('id') ?? (int) $sale->created_by;
        $saleDate = CarbonImmutable::parse($sale->sale_date)->startOfDay();

        // 1. Ensure beginning balance movement exists for the 50L fulfillment
        $begMovement = DB::table('inventory_movements')
            ->where('storage_location_id', $tank->id)
            ->where('fuel_type_id', $dieselFuel->id)
            ->where('movement_type', 'beginning')
            ->where('reference_type', 'opening_balance')
            ->first();

        if (! $begMovement) {
            $begMovementCode = $this->generateUniqueCode('inventory_movements', 'movement_code', 'MOV');
            $begMovementId = DB::table('inventory_movements')->insertGetId([
                'movement_code' => $begMovementCode,
                'storage_location_id' => $tank->id,
                'fuel_type_id' => $dieselFuel->id,
                'movement_type' => 'beginning',
                'direction' => 'in',
                'quantity_liters' => 50.00,
                'unit_cost' => 50.00,
                'reference_type' => 'opening_balance',
                'reference_id' => $sale->id,
                'movement_date' => $saleDate->toDateTimeString(),
                'remarks' => 'Authoritative opening inventory for historical sale SLS-000001',
                'created_by' => $inventoryOfficerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $begMovementId = $begMovement->id;
        }

        // 2. Ensure released stock-out exists for this sale
        $stockOut = DB::table('stock_outs')->where('sale_id', $sale->id)->first();
        if (! $stockOut) {
            $outMovementCode = $this->generateUniqueCode('inventory_movements', 'movement_code', 'MOV');
            $outMovementId = DB::table('inventory_movements')->insertGetId([
                'movement_code' => $outMovementCode,
                'storage_location_id' => $tank->id,
                'fuel_type_id' => $dieselFuel->id,
                'movement_type' => 'stock_out',
                'direction' => 'out',
                'quantity_liters' => 50.00,
                'unit_cost' => 50.00,
                'reference_type' => 'stock_out',
                'reference_id' => $sale->id, // temporarily sale id until stock_out inserted
                'movement_date' => $saleDate->addHours(14)->toDateTimeString(),
                'remarks' => 'Authoritative stock-out for historical sale SLS-000001',
                'created_by' => $inventoryOfficerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $stockOutCode = $this->generateUniqueCode('stock_outs', 'stock_out_code', 'STO');
            $stockOutId = DB::table('stock_outs')->insertGetId([
                'stock_out_code' => $stockOutCode,
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'customer_id' => $sale->customer_id,
                'fuel_type_id' => $dieselFuel->id,
                'storage_location_id' => $tank->id,
                'source_type' => 'garage',
                'depot_id' => null,
                'haul_allocation_id' => null,
                'inventory_movement_id' => $outMovementId,
                'quantity_liters' => 50.00,
                'stock_out_at' => $saleDate->addHours(14)->toDateTimeString(),
                'status' => 'released',
                'created_by' => $inventoryOfficerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('inventory_movements')->where('id', $outMovementId)->update([
                'reference_id' => $stockOutId,
                'updated_at' => now(),
            ]);

            DB::table('sale_items')->where('id', $item->id)->update([
                'fulfilled_quantity_liters' => 50.00,
                'updated_at' => now(),
            ]);
        }

        $this->saleConfirmationService->reconcile((int) $sale->id);

        return true;
    }

    /**
     * Provision and verify production master data:
     * - Approved fuel types (F1, UNL, DSL, PREM)
     * - 2 active garage tanks per fuel
     * - Active depots
     * - Active trucks (hauling, delivery, mixed)
     * - Active drivers and driver profiles
     * - Active verified commercial and retail customers
     *
     * @return array<string, int>
     */
    public function seedMasterData(): array
    {
        // 0. Ensure required staff role users exist
        foreach ([
            'admin' => ['name' => 'Administrator', 'email' => 'admin@gmail.com'],
            'inventory_officer' => ['name' => 'Inventory Officer', 'email' => 'inventoryofficer@gmail.com'],
            'sales_officer' => ['name' => 'Sales Officer', 'email' => 'salesofficer@gmail.com'],
            'dispatch_officer' => ['name' => 'Dispatch Officer', 'email' => 'dispatchofficer@gmail.com'],
            'driver' => ['name' => 'Driver User', 'email' => 'driver@gmail.com'],
        ] as $role => $userData) {
            $userExists = DB::table('users')->where('role', $role)->exists();
            if (! $userExists) {
                DB::table('users')->insert([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'role' => $role,
                    'password' => Hash::make('password123'),
                    'status' => 'active',
                    'approval_status' => 'approved',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (! DB::table('users')->where('email', 'driver2@gmail.com')->exists()) {
            DB::table('users')->insert([
                'name' => 'Driver Two',
                'email' => 'driver2@gmail.com',
                'role' => 'driver',
                'password' => Hash::make('password123'),
                'status' => 'active',
                'approval_status' => 'approved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 1. Approved Fuels
        foreach ([
            ['code' => 'F1', 'name' => 'F1'],
            ['code' => 'UNL', 'name' => 'UNLEADED'],
            ['code' => 'PREM', 'name' => 'PREMIUM'],
            ['code' => 'DSL', 'name' => 'DIESEL'],
        ] as $fuel) {
            DB::table('fuel_types')->updateOrInsert(
                ['code' => $fuel['code']],
                [
                    'name' => $fuel['name'],
                    'status' => 'active',
                    'updated_at' => now(),
                ]
            );
        }

        DB::table('fuel_types')
            ->whereNotIn('code', ['F1', 'UNL', 'PREM', 'DSL'])
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        // 2. Active garage tanks (2 per approved fuel)
        $this->garageTankService->ensureForActiveFuelTypes();

        // 3. Verified Depots
        DB::table('depots')->updateOrInsert(
            ['depot_code' => 'DEP-CJP-MAIN'],
            [
                'name' => 'CJP Main Depot - Subic',
                'address' => 'Subic Bay Freeport Zone, Zambales',
                'contact_person' => 'Depot Manager Subic',
                'phone' => '+63 47 252 1000',
                'status' => 'active',
                'updated_at' => now(),
            ]
        );

        DB::table('depots')->updateOrInsert(
            ['depot_code' => 'DEP-BAT-01'],
            [
                'name' => 'Batangas Energy Terminal',
                'address' => 'Shell Tabangao, Batangas City',
                'contact_person' => 'Terminal Supervisor Batangas',
                'phone' => '+63 43 723 2000',
                'status' => 'active',
                'updated_at' => now(),
            ]
        );

        // 4. Operational Trucks
        foreach ([
            [
                'truck_code' => 'TRK-001',
                'plate_number' => 'NFD-4821',
                'capacity_liters' => 40000.00,
                'truck_type' => 'hauling',
                'status' => 'available',
            ],
            [
                'truck_code' => 'TRK-002',
                'plate_number' => 'CAE-9214',
                'capacity_liters' => 24000.00,
                'truck_type' => 'delivery',
                'status' => 'available',
            ],
            [
                'truck_code' => 'TRK-003',
                'plate_number' => 'WBC-5108',
                'capacity_liters' => 30000.00,
                'truck_type' => 'mixed',
                'status' => 'available',
            ],
        ] as $truckData) {
            DB::table('trucks')->updateOrInsert(
                ['truck_code' => $truckData['truck_code']],
                array_merge($truckData, ['updated_at' => now()])
            );
        }

        // 5. Driver Profiles
        $primaryDriverUser = DB::table('users')->where('role', 'driver')->first();
        if ($primaryDriverUser) {
            DB::table('driver_profiles')->updateOrInsert(
                ['user_id' => $primaryDriverUser->id],
                [
                    'driver_code' => 'DRV-001',
                    'license_number' => 'N01-19-123456',
                    'emergency_contact' => '+639171234567',
                    'status' => 'available',
                    'updated_at' => now(),
                ]
            );
        }

        // 6. Verified Customers
        DB::table('customers')->updateOrInsert(
            ['customer_code' => 'CSM-000001'],
            [
                'name' => 'John Luke Reynon',
                'company_name' => 'Reynon Trading Enterprises',
                'location' => 'San Fernando, Pampanga',
                'email' => 'reynon@example.com',
                'phone' => '+639171112233',
                'payment_status' => 'clear',
                'status' => 'active',
                'updated_at' => now(),
            ]
        );

        DB::table('customers')->updateOrInsert(
            ['customer_code' => 'CSM-000002'],
            [
                'name' => 'Northern Luzon Transport Corp',
                'company_name' => 'Northern Luzon Transport Corp',
                'location' => 'Tarlac City, Tarlac',
                'email' => 'fleet@nltransport.com',
                'phone' => '+639182223344',
                'payment_status' => 'clear',
                'status' => 'active',
                'updated_at' => now(),
            ]
        );

        DB::table('customers')->updateOrInsert(
            ['customer_code' => 'CSM-000003'],
            [
                'name' => 'Apex Construction & Heavy Equipment',
                'company_name' => 'Apex Construction & Heavy Equipment Inc',
                'location' => 'Angeles City, Pampanga',
                'email' => 'operations@apexheavy.com',
                'phone' => '+639193334455',
                'payment_status' => 'clear',
                'status' => 'active',
                'updated_at' => now(),
            ]
        );

        return [
            'fuel_types' => DB::table('fuel_types')->where('status', 'active')->count(),
            'storage_locations' => DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->count(),
            'depots' => DB::table('depots')->where('status', 'active')->count(),
            'trucks' => DB::table('trucks')->where('status', 'available')->count(),
            'driver_profiles' => DB::table('driver_profiles')->where('status', 'available')->count(),
            'customers' => DB::table('customers')->where('status', 'active')->count(),
        ];
    }

    /**
     * Seed reproducible, reconciled operational acceptance workflows covering all 4 fuels:
     * - Diesel (DSL): Garage fulfillment
     * - Unleaded (UNL): Direct-depot fulfillment & garage split
     * - Premium (PREM): Garage fulfillment
     * - F1 (F1): Garage fulfillment
     *
     * @return array<string, array<string, mixed>>
     */
    public function seedOperationalAcceptanceData(): array
    {
        $this->seedMasterData();

        $depot = DB::table('depots')->where('depot_code', 'DEP-CJP-MAIN')->first();
        $inventoryOfficer = DB::table('users')->where('role', 'inventory_officer')->first();
        $salesOfficer = DB::table('users')->where('role', 'sales_officer')->first();
        $dispatchOfficer = DB::table('users')->where('role', 'dispatch_officer')->first();
        $primaryDriverUser = DB::table('users')->where('role', 'driver')->first();
        $secondaryDriverUser = DB::table('users')->where('role', 'driver')->where('id', '!=', $primaryDriverUser->id)->first() ?? $primaryDriverUser;

        $truckHauling = DB::table('trucks')->where('truck_code', 'TRK-001')->first();
        $truckDelivery = DB::table('trucks')->where('truck_code', 'TRK-002')->first();
        $truckMixed = DB::table('trucks')->where('truck_code', 'TRK-003')->first();

        $custRetail = DB::table('customers')->where('customer_code', 'CSM-000001')->first();
        $custTransport = DB::table('customers')->where('customer_code', 'CSM-000002')->first();
        $custApex = DB::table('customers')->where('customer_code', 'CSM-000003')->first();

        $fuels = DB::table('fuel_types')
            ->whereIn('code', ['DSL', 'UNL', 'PREM', 'F1'])
            ->get()
            ->keyBy('code');

        $tanks = DB::table('storage_locations')
            ->where('type', 'garage')
            ->where('status', 'active')
            ->where('tank_number', 1)
            ->get()
            ->keyBy('fuel_type_id');

        $workflows = [];

        // ─────────────────────────────────────────────────────────────────
        // 1. DIESEL (DSL) — Full Garage Fulfillment Acceptance
        // ─────────────────────────────────────────────────────────────────
        $dslFuel = $fuels['DSL'];
        $dslTank = $tanks[$dslFuel->id];
        $workflows['DSL'] = $this->createReconciledWorkflow([
            'fuel_type_id' => $dslFuel->id,
            'purchase_code' => 'PUR-ACC-DSL-001',
            'haul_code' => 'LFT-ACC-DSL-001',
            'sale_code' => 'SLS-ACC-DSL-001',
            'payment_code' => 'PAY-ACC-DSL-001',
            'dr_number' => 'DR-DSL-2026-001',
            'purchase_date' => '2026-09-08',
            'ordered_liters' => 40000.00,
            'unit_cost' => 48.00,
            'depot_id' => $depot->id,
            'truck_id' => $truckHauling->id,
            'driver_user_id' => $primaryDriverUser->id,
            'scheduled_at' => '2026-09-08 08:00:00',
            'hauled_at' => '2026-09-08 11:30:00',
            'destination_type' => 'garage',
            'storage_location_id' => $dslTank->id,
            'stock_in_liters' => 40000.00,
            'sale_date' => '2026-09-09',
            'customer_id' => $custTransport->id,
            'sale_liters' => 15000.00,
            'unit_price' => 56.00,
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'BNK-DSL-987654',
            'source_type' => 'garage',
            'users' => [
                'inventory' => $inventoryOfficer->id,
                'sales' => $salesOfficer->id,
                'dispatch' => $dispatchOfficer->id,
            ],
        ]);

        // ─────────────────────────────────────────────────────────────────
        // 2. UNLEADED (UNL) — Direct-Depot & Garage Split Fulfillment Acceptance
        // ─────────────────────────────────────────────────────────────────
        $unlFuel = $fuels['UNL'];
        $unlTank = $tanks[$unlFuel->id];
        $workflows['UNL'] = $this->createReconciledDirectDepotWorkflow([
            'fuel_type_id' => $unlFuel->id,
            'purchase_code' => 'PUR-ACC-UNL-001',
            'haul_code' => 'LFT-ACC-UNL-001',
            'sale_code' => 'SLS-ACC-UNL-001',
            'payment_code' => 'PAY-ACC-UNL-001',
            'dr_number' => 'DR-UNL-2026-001',
            'purchase_date' => '2026-09-09',
            'ordered_liters' => 30000.00,
            'unit_cost' => 52.00,
            'depot_id' => $depot->id,
            'truck_id' => $truckMixed->id,
            'driver_user_id' => $primaryDriverUser->id,
            'scheduled_at' => '2026-09-09 09:00:00',
            'hauled_at' => '2026-09-09 13:00:00',
            'garage_tank_id' => $unlTank->id,
            'garage_allocation_liters' => 18000.00,
            'direct_allocation_liters' => 12000.00,
            'customer_id' => $custApex->id,
            'sale_liters' => 12000.00,
            'unit_price' => 61.00,
            'payment_method' => 'cheque',
            'payment_reference' => 'CHK-UNL-554433',
            'users' => [
                'inventory' => $inventoryOfficer->id,
                'sales' => $salesOfficer->id,
                'dispatch' => $dispatchOfficer->id,
            ],
        ]);

        // ─────────────────────────────────────────────────────────────────
        // 3. PREMIUM (PREM) — Full Garage Fulfillment Acceptance
        // ─────────────────────────────────────────────────────────────────
        $premFuel = $fuels['PREM'];
        $premTank = $tanks[$premFuel->id];
        $workflows['PREM'] = $this->createReconciledWorkflow([
            'fuel_type_id' => $premFuel->id,
            'purchase_code' => 'PUR-ACC-PREM-001',
            'haul_code' => 'LFT-ACC-PREM-001',
            'sale_code' => 'SLS-ACC-PREM-001',
            'payment_code' => 'PAY-ACC-PREM-001',
            'dr_number' => 'DR-PREM-2026-001',
            'purchase_date' => '2026-09-10',
            'ordered_liters' => 20000.00,
            'unit_cost' => 55.00,
            'depot_id' => $depot->id,
            'truck_id' => $truckDelivery->id,
            'driver_user_id' => $secondaryDriverUser->id,
            'scheduled_at' => '2026-09-10 08:30:00',
            'hauled_at' => '2026-09-10 11:45:00',
            'destination_type' => 'garage',
            'storage_location_id' => $premTank->id,
            'stock_in_liters' => 20000.00,
            'sale_date' => '2026-09-11',
            'customer_id' => $custRetail->id,
            'sale_liters' => 8000.00,
            'unit_price' => 65.00,
            'payment_method' => 'cash_on_delivery',
            'payment_reference' => 'RCPT-PREM-001',
            'source_type' => 'garage',
            'users' => [
                'inventory' => $inventoryOfficer->id,
                'sales' => $salesOfficer->id,
                'dispatch' => $dispatchOfficer->id,
            ],
        ]);

        // ─────────────────────────────────────────────────────────────────
        // 4. F1 (F1) — Full Garage Fulfillment Acceptance
        // ─────────────────────────────────────────────────────────────────
        $f1Fuel = $fuels['F1'];
        $f1Tank = $tanks[$f1Fuel->id];
        $workflows['F1'] = $this->createReconciledWorkflow([
            'fuel_type_id' => $f1Fuel->id,
            'purchase_code' => 'PUR-ACC-F1-001',
            'haul_code' => 'LFT-ACC-F1-001',
            'sale_code' => 'SLS-ACC-F1-001',
            'payment_code' => 'PAY-ACC-F1-001',
            'dr_number' => 'DR-F1-2026-001',
            'purchase_date' => '2026-09-11',
            'ordered_liters' => 10000.00,
            'unit_cost' => 58.00,
            'depot_id' => $depot->id,
            'truck_id' => $truckDelivery->id,
            'driver_user_id' => $secondaryDriverUser->id,
            'scheduled_at' => '2026-09-11 09:30:00',
            'hauled_at' => '2026-09-11 12:30:00',
            'destination_type' => 'garage',
            'storage_location_id' => $f1Tank->id,
            'stock_in_liters' => 10000.00,
            'sale_date' => '2026-09-12',
            'customer_id' => $custTransport->id,
            'sale_liters' => 3000.00,
            'unit_price' => 70.00,
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'BNK-F1-112233',
            'source_type' => 'garage',
            'users' => [
                'inventory' => $inventoryOfficer->id,
                'sales' => $salesOfficer->id,
                'dispatch' => $dispatchOfficer->id,
            ],
        ]);

        return $workflows;
    }

    /**
     * Create a reconciled garage-fulfilled operational workflow.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createReconciledWorkflow(array $data): array
    {
        $existingPurchase = DB::table('purchases')->where('purchase_code', $data['purchase_code'])->first();
        if ($existingPurchase) {
            $haul = DB::table('hauls')->where('haul_code', $data['haul_code'])->first();
            $sale = DB::table('sales')->where('sale_code', $data['sale_code'])->first();
            $stockOut = DB::table('stock_outs')->where('sale_id', $sale?->id)->first();
            $payment = DB::table('payments')->where('payment_code', $data['payment_code'])->first();

            return [
                'purchase_id' => $existingPurchase->id,
                'purchase_code' => $data['purchase_code'],
                'haul_id' => $haul?->id,
                'haul_code' => $data['haul_code'],
                'sale_id' => $sale?->id,
                'sale_code' => $data['sale_code'],
                'stock_out_id' => $stockOut?->id,
                'stock_out_code' => $stockOut?->stock_out_code,
                'payment_id' => $payment?->id,
                'payment_code' => $data['payment_code'],
                'total_amount' => round((float) $data['sale_liters'] * (float) $data['unit_price'], 2),
            ];
        }

        // 1. Purchase
        $lineTotal = round((float) $data['ordered_liters'] * (float) $data['unit_cost'], 2);
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => $data['purchase_code'],
            'depot_id' => $data['depot_id'],
            'purchase_date' => $data['purchase_date'],
            'receipt_reference' => 'REF-'.Str::upper(Str::random(6)),
            'payment_status' => 'paid',
            'status' => 'hauled',
            'created_by' => $data['users']['inventory'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $data['fuel_type_id'],
            'quantity_ordered_liters' => $data['ordered_liters'],
            'unit_cost' => $data['unit_cost'],
            'line_total' => $lineTotal,
            'quantity_hauled_liters' => $data['ordered_liters'],
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Haul
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => $data['haul_code'],
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $data['depot_id'],
            'fuel_type_id' => $data['fuel_type_id'],
            'truck_id' => $data['truck_id'],
            'driver_user_id' => $data['driver_user_id'],
            'dr_number' => $data['dr_number'],
            'scheduled_at' => $data['scheduled_at'],
            'hauled_at' => $data['hauled_at'],
            'source_location' => 'Depot Loading Bay',
            'quantity_liters' => $data['ordered_liters'],
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Haul Allocation (Garage)
        $allocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $data['fuel_type_id'],
            'destination_type' => 'garage',
            'storage_location_id' => $data['storage_location_id'],
            'customer_id' => null,
            'sale_id' => null,
            'quantity_liters' => $data['ordered_liters'],
            'allocated_at' => $data['hauled_at'],
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Stock-In Movement
        $inMovementCode = $this->generateUniqueCode('inventory_movements', 'movement_code', 'MOV');
        $inMovementId = DB::table('inventory_movements')->insertGetId([
            'movement_code' => $inMovementCode,
            'storage_location_id' => $data['storage_location_id'],
            'fuel_type_id' => $data['fuel_type_id'],
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => $data['stock_in_liters'],
            'unit_cost' => $data['unit_cost'],
            'reference_type' => 'haul_allocation',
            'reference_id' => $allocationId,
            'movement_date' => $data['hauled_at'],
            'remarks' => 'Stock-In from haul '.$data['haul_code'],
            'created_by' => $data['users']['inventory'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Sale
        $saleTotal = round((float) $data['sale_liters'] * (float) $data['unit_price'], 2);
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $data['sale_code'],
            'sales_order_number' => 'SO-'.Str::upper(Str::random(6)),
            'customer_id' => $data['customer_id'],
            'sale_date' => $data['sale_date'],
            'payment_method' => $data['payment_method'],
            'payment_terms' => 'cod',
            'status' => 'paid',
            'created_by' => $data['users']['sales'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $data['fuel_type_id'],
            'quantity_liters' => $data['sale_liters'],
            'unit_price' => $data['unit_price'],
            'line_total' => $saleTotal,
            'fulfilled_quantity_liters' => $data['sale_liters'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 6. Stock-Out Outgoing Movement & Stock-Out Record
        $outMovementCode = $this->generateUniqueCode('inventory_movements', 'movement_code', 'MOV');
        $outMovementId = DB::table('inventory_movements')->insertGetId([
            'movement_code' => $outMovementCode,
            'storage_location_id' => $data['storage_location_id'],
            'fuel_type_id' => $data['fuel_type_id'],
            'movement_type' => 'stock_out',
            'direction' => 'out',
            'quantity_liters' => $data['sale_liters'],
            'unit_cost' => $data['unit_cost'],
            'reference_type' => 'stock_out',
            'reference_id' => $saleId,
            'movement_date' => $data['sale_date'].' 14:00:00',
            'remarks' => 'Stock-Out for sale '.$data['sale_code'],
            'created_by' => $data['users']['inventory'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stockOutCode = $this->generateUniqueCode('stock_outs', 'stock_out_code', 'STO');
        $stockOutId = DB::table('stock_outs')->insertGetId([
            'stock_out_code' => $stockOutCode,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $data['customer_id'],
            'fuel_type_id' => $data['fuel_type_id'],
            'storage_location_id' => $data['storage_location_id'],
            'source_type' => 'garage',
            'depot_id' => null,
            'haul_allocation_id' => null,
            'inventory_movement_id' => $outMovementId,
            'quantity_liters' => $data['sale_liters'],
            'stock_out_at' => $data['sale_date'].' 14:00:00',
            'status' => 'released',
            'created_by' => $data['users']['inventory'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->where('id', $outMovementId)->update([
            'reference_id' => $stockOutId,
            'updated_at' => now(),
        ]);

        // 7. Payment & Receivable
        $paymentId = DB::table('payments')->insertGetId([
            'payment_code' => $data['payment_code'],
            'sale_id' => $saleId,
            'payment_schedule_id' => null,
            'payment_date' => $data['sale_date'],
            'amount' => $saleTotal,
            'method' => $data['payment_method'],
            'reference_number' => $data['payment_reference'],
            'remarks' => 'Acceptance payment for '.$data['sale_code'],
            'received_by' => $data['users']['sales'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->saleConfirmationService->reconcile((int) $saleId);

        return [
            'purchase_id' => $purchaseId,
            'purchase_code' => $data['purchase_code'],
            'haul_id' => $haulId,
            'haul_code' => $data['haul_code'],
            'sale_id' => $saleId,
            'sale_code' => $data['sale_code'],
            'stock_out_id' => $stockOutId,
            'stock_out_code' => $stockOutCode,
            'payment_id' => $paymentId,
            'payment_code' => $data['payment_code'],
            'total_amount' => $saleTotal,
        ];
    }

    /**
     * Create a reconciled direct-depot + garage split operational workflow.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createReconciledDirectDepotWorkflow(array $data): array
    {
        $existingPurchase = DB::table('purchases')->where('purchase_code', $data['purchase_code'])->first();
        if ($existingPurchase) {
            $haul = DB::table('hauls')->where('haul_code', $data['haul_code'])->first();
            $sale = DB::table('sales')->where('sale_code', $data['sale_code'])->first();
            $stockOut = DB::table('stock_outs')->where('sale_id', $sale?->id)->first();
            $payment = DB::table('payments')->where('payment_code', $data['payment_code'])->first();

            return [
                'purchase_id' => $existingPurchase->id,
                'purchase_code' => $data['purchase_code'],
                'haul_id' => $haul?->id,
                'haul_code' => $data['haul_code'],
                'sale_id' => $sale?->id,
                'sale_code' => $data['sale_code'],
                'stock_out_id' => $stockOut?->id,
                'stock_out_code' => $stockOut?->stock_out_code,
                'payment_id' => $payment?->id,
                'payment_code' => $data['payment_code'],
                'total_amount' => round((float) $data['sale_liters'] * (float) $data['unit_price'], 2),
            ];
        }

        // 1. Pre-create Sale for direct depot delivery
        $saleTotal = round((float) $data['sale_liters'] * (float) $data['unit_price'], 2);
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $data['sale_code'],
            'sales_order_number' => 'SO-'.Str::upper(Str::random(6)),
            'customer_id' => $data['customer_id'],
            'sale_date' => $data['purchase_date'],
            'payment_method' => $data['payment_method'],
            'payment_terms' => 'cod',
            'status' => 'paid',
            'created_by' => $data['users']['sales'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $data['fuel_type_id'],
            'quantity_liters' => $data['sale_liters'],
            'unit_price' => $data['unit_price'],
            'line_total' => $saleTotal,
            'fulfilled_quantity_liters' => $data['sale_liters'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Purchase
        $lineTotal = round((float) $data['ordered_liters'] * (float) $data['unit_cost'], 2);
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => $data['purchase_code'],
            'depot_id' => $data['depot_id'],
            'purchase_date' => $data['purchase_date'],
            'receipt_reference' => 'REF-'.Str::upper(Str::random(6)),
            'payment_status' => 'paid',
            'status' => 'hauled',
            'created_by' => $data['users']['inventory'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $data['fuel_type_id'],
            'quantity_ordered_liters' => $data['ordered_liters'],
            'unit_cost' => $data['unit_cost'],
            'line_total' => $lineTotal,
            'quantity_hauled_liters' => $data['ordered_liters'],
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Haul
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => $data['haul_code'],
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $data['depot_id'],
            'fuel_type_id' => $data['fuel_type_id'],
            'truck_id' => $data['truck_id'],
            'driver_user_id' => $data['driver_user_id'],
            'dr_number' => $data['dr_number'],
            'scheduled_at' => $data['scheduled_at'],
            'hauled_at' => $data['hauled_at'],
            'source_location' => 'Depot Loading Bay',
            'quantity_liters' => $data['ordered_liters'],
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Split Haul Allocations:
        // A: Direct Customer Allocation
        $directAllocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $data['fuel_type_id'],
            'destination_type' => 'customer',
            'storage_location_id' => null,
            'customer_id' => $data['customer_id'],
            'sale_id' => $saleId,
            'quantity_liters' => $data['direct_allocation_liters'],
            'allocated_at' => $data['hauled_at'],
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // B: Garage Tank Allocation
        $garageAllocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $data['fuel_type_id'],
            'destination_type' => 'garage',
            'storage_location_id' => $data['garage_tank_id'],
            'customer_id' => null,
            'sale_id' => null,
            'quantity_liters' => $data['garage_allocation_liters'],
            'allocated_at' => $data['hauled_at'],
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Stock-In Movement for Garage Portion
        $inMovementCode = $this->generateUniqueCode('inventory_movements', 'movement_code', 'MOV');
        DB::table('inventory_movements')->insert([
            'movement_code' => $inMovementCode,
            'storage_location_id' => $data['garage_tank_id'],
            'fuel_type_id' => $data['fuel_type_id'],
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => $data['garage_allocation_liters'],
            'unit_cost' => $data['unit_cost'],
            'reference_type' => 'haul_allocation',
            'reference_id' => $garageAllocationId,
            'movement_date' => $data['hauled_at'],
            'remarks' => 'Stock-In for garage allocation from haul '.$data['haul_code'],
            'created_by' => $data['users']['inventory'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 6. Direct Depot Stock-Out (no garage inventory movement)
        $stockOutCode = $this->generateUniqueCode('stock_outs', 'stock_out_code', 'STO');
        $stockOutId = DB::table('stock_outs')->insertGetId([
            'stock_out_code' => $stockOutCode,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $data['customer_id'],
            'fuel_type_id' => $data['fuel_type_id'],
            'storage_location_id' => null,
            'source_type' => 'depot',
            'depot_id' => $data['depot_id'],
            'haul_allocation_id' => $directAllocationId,
            'inventory_movement_id' => null,
            'quantity_liters' => $data['direct_allocation_liters'],
            'stock_out_at' => $data['hauled_at'],
            'status' => 'released',
            'created_by' => $data['users']['inventory'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 7. Payment
        $paymentId = DB::table('payments')->insertGetId([
            'payment_code' => $data['payment_code'],
            'sale_id' => $saleId,
            'payment_schedule_id' => null,
            'payment_date' => $data['purchase_date'],
            'amount' => $saleTotal,
            'method' => $data['payment_method'],
            'reference_number' => $data['payment_reference'],
            'remarks' => 'Direct depot delivery payment for '.$data['sale_code'],
            'received_by' => $data['users']['sales'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->saleConfirmationService->reconcile((int) $saleId);

        return [
            'purchase_id' => $purchaseId,
            'purchase_code' => $data['purchase_code'],
            'haul_id' => $haulId,
            'haul_code' => $data['haul_code'],
            'sale_id' => $saleId,
            'sale_code' => $data['sale_code'],
            'stock_out_id' => $stockOutId,
            'stock_out_code' => $stockOutCode,
            'payment_id' => $paymentId,
            'payment_code' => $data['payment_code'],
            'total_amount' => $saleTotal,
        ];
    }

    /**
     * Compute a full reconciliation summary of operational data.
     *
     * @return array<string, mixed>
     */
    public function reconcileSummary(): array
    {
        $salesMissingReceivables = DB::table('sales')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereNull('receivables.id')
            ->count();

        $overpaidSales = DB::table('sales')
            ->joinSub(DB::table('sale_items')->selectRaw('sale_id, SUM(line_total) total')->groupBy('sale_id'), 'sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->joinSub(DB::table('payments')->selectRaw('sale_id, SUM(amount) paid')->groupBy('sale_id'), 'payment_totals', 'payment_totals.sale_id', '=', 'sales.id')
            ->whereRaw('payment_totals.paid > sale_totals.total')
            ->count();

        $overFulfilledItems = DB::table('sale_items')
            ->whereColumn('fulfilled_quantity_liters', '>', 'quantity_liters')
            ->count();

        $unfulfilledPaidSales = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.status', 'paid')
            ->where('sale_items.fulfilled_quantity_liters', '<=', 0)
            ->count();

        $releasedWithoutMovement = DB::table('stock_outs')
            ->where('status', 'released')
            ->where('source_type', 'garage')
            ->whereNull('inventory_movement_id')
            ->count();

        $negativeBalances = DB::query()
            ->fromSub(
                DB::table('inventory_movements')
                    ->selectRaw("storage_location_id, fuel_type_id, SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END) as balance")
                    ->groupBy('storage_location_id', 'fuel_type_id')
                    ->havingRaw('balance < 0'),
                'balances'
            )
            ->count();

        return [
            'sales_missing_receivables' => $salesMissingReceivables,
            'overpaid_sales' => $overpaidSales,
            'over_fulfilled_items' => $overFulfilledItems,
            'unfulfilled_paid_sales' => $unfulfilledPaidSales,
            'released_without_movement' => $releasedWithoutMovement,
            'negative_inventory_balances' => $negativeBalances,
            'total_purchases' => DB::table('purchases')->count(),
            'total_hauls' => DB::table('hauls')->count(),
            'total_haul_allocations' => DB::table('haul_allocations')->count(),
            'total_inventory_movements' => DB::table('inventory_movements')->count(),
            'total_stock_outs' => DB::table('stock_outs')->count(),
            'total_sales' => DB::table('sales')->count(),
            'total_payments' => DB::table('payments')->count(),
        ];
    }

    private function generateUniqueCode(string $table, string $column, string $prefix): string
    {
        for ($i = 0; $i < 10; $i++) {
            $code = $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
            if (! DB::table($table)->where($column, $code)->exists()) {
                return $code;
            }
        }

        return $prefix.'-'.now()->format('ymdHis').'-'.Str::upper(Str::random(6));
    }
}
