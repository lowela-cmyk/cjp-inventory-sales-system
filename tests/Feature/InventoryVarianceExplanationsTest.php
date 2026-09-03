<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InventoryVarianceExplanationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InventoryVarianceExplanationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_generate_inventory_variance_explanation_from_system_values(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $records = $this->records();
        $this->varianceData($records);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "Inventory Variance Summary\nFour records require verification.\n\nMain Variance Detected\nMissing Stock-Out and Quantity Mismatch are present.\n\nAffected Area/Fuel Type\nAI Diesel and AI E10 appear in the variance sample.\n\nPossible Concern\nThe records require verification before any correction.\n\nRecommended Verification\nReview the listed sale and stock-out references.",
                    ],
                ]],
            ]),
        ]);
        config($this->aiConfig());

        $response = $this->actingAs($records['admin'])->post(route('admin.dashboard.inventory-variance-explanation'), [
            'variance_date_from' => '2026-09-01',
            'variance_date_to' => '2026-09-02',
        ]);

        $response->assertRedirect(route('admin.dashboard', [
            'variance_date_from' => '2026-09-01',
            'variance_date_to' => '2026-09-02',
            'variance_fuel_type_id' => null,
            'variance_customer_id' => null,
            'variance_status' => null,
        ]));
        $response->assertSessionHas('inventoryVarianceExplanation');

        $this->followingRedirects()
            ->actingAs($records['admin'])
            ->get(route('admin.dashboard', [
                'variance_date_from' => '2026-09-01',
                'variance_date_to' => '2026-09-02',
            ]))
            ->assertOk()
            ->assertSee('Inventory Variance AI Explanation')
            ->assertSee('Inventory Variance Summary')
            ->assertSee('AI-assisted explanation based on system-detected inventory variance');

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $promptText = $body['messages'][0]['content']."\n".$body['messages'][1]['content'];

            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-groq-key')
                && $body['model'] === 'openai/gpt-oss-20b'
                && $body['max_tokens'] === 420
                && str_contains($promptText, InventoryVarianceExplanationService::SYSTEM_PROMPT)
                && str_contains($promptText, '"transactions_checked": 6')
                && str_contains($promptText, '"matched_transactions": 2')
                && str_contains($promptText, '"variance_count": 4')
                && str_contains($promptText, '"variance_rate_percent": 66.7')
                && str_contains($promptText, '"quantity_difference_liters": -2500')
                && str_contains($promptText, '"reason": "Missing Stock-Out"')
                && str_contains($promptText, '"reason": "Quantity Mismatch"')
                && str_contains($promptText, '"reason": "Duplicate Relationship"')
                && str_contains($promptText, '"reason": "Missing Sale\/Receivable"')
                && str_contains($promptText, '"fuel_type": "AI Diesel"')
                && str_contains($promptText, '"fuel_type": "AI E10"')
                && str_contains($promptText, 'requires verification')
                && str_contains($promptText, 'Unpaid or partially paid valid sales are not automatically inventory variance')
                && ! str_contains($promptText, 'Variance Customer')
                && ! str_contains($promptText, 'variance@example.com')
                && ! array_key_exists('key', $body);
        });

        Carbon::setTestNow();
    }

    public function test_variance_filters_are_applied_to_ai_payload_without_customer_details(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $records = $this->records();
        $this->varianceData($records);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => "Inventory Variance Summary\nFiltered variance requires verification."]]],
            ]),
        ]);
        config($this->aiConfig());

        $this->actingAs($records['admin'])->post(route('admin.dashboard.inventory-variance-explanation'), [
            'variance_date_from' => '2026-09-01',
            'variance_date_to' => '2026-09-02',
            'variance_fuel_type_id' => $records['e10Id'],
            'variance_customer_id' => $records['customerId'],
            'variance_status' => 'variance',
        ])->assertRedirect();

        Http::assertSent(function ($request) use ($records): bool {
            $body = $request->data();
            $promptText = $body['messages'][1]['content'];

            return str_contains($promptText, '"transactions_checked": 1')
                && str_contains($promptText, '"variance_count": 1')
                && str_contains($promptText, '"quantity_difference_liters": -1500')
                && str_contains($promptText, '"fuel_type_id": '.$records['e10Id'])
                && str_contains($promptText, '"customer_id_applied": true')
                && ! str_contains($promptText, 'Variance Customer')
                && ! str_contains($promptText, 'variance@example.com');
        });

        Carbon::setTestNow();
    }

    public function test_no_variance_and_zero_data_period_skip_ai_call(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $records = $this->records();
        [$saleId, $saleItemId] = $this->saleWithItem($records, 'SLS-VAR-MATCHED-UNPAID', 1000, 50, 'unpaid');
        $this->stockOutForSale($records, $saleId, $saleItemId, 'STO-VAR-MATCHED-UNPAID', 1000);
        Http::fake();
        config($this->aiConfig());

        $this->actingAs($records['admin'])
            ->post(route('admin.dashboard.inventory-variance-explanation'), [
                'variance_date_from' => '2026-09-01',
                'variance_date_to' => '2026-09-02',
            ])
            ->assertRedirect()
            ->assertSessionHas('inventoryVarianceExplanationNotice', 'No inventory variance requiring explanation was detected for the selected period.');

        $this->actingAs($records['admin'])
            ->post(route('admin.dashboard.inventory-variance-explanation'), [
                'variance_date_from' => '2024-01-01',
                'variance_date_to' => '2024-01-31',
            ])
            ->assertRedirect()
            ->assertSessionHas('inventoryVarianceExplanationNotice', 'No inventory variance requiring explanation was detected for the selected period.');

        Http::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_ai_failure_shows_friendly_fallback(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $records = $this->records();
        $this->varianceData($records);
        config($this->aiConfig());

        foreach ([
            Http::response(['unexpected' => true]),
            Http::response(['error' => ['message' => 'provider down']], 503),
        ] as $fakeResponse) {
            Http::fake(['api.groq.com/*' => $fakeResponse]);

            $this->actingAs($records['admin'])
                ->post(route('admin.dashboard.inventory-variance-explanation'), [
                    'variance_date_from' => '2026-09-01',
                    'variance_date_to' => '2026-09-02',
                ])
                ->assertRedirect()
                ->assertSessionHas('inventoryVarianceExplanationNotice', 'AI insights are temporarily unavailable. System analytics are still available.');
        }

        Carbon::setTestNow();
    }

    public function test_driver_cannot_generate_inventory_variance_explanation(): void
    {
        Http::fake();
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);

        $this->actingAs($driver)
            ->post(route('admin.dashboard.inventory-variance-explanation'), [])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-VAR-AI',
            'name' => 'Variance Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-VAR-AI',
            'name' => 'Variance Customer',
            'company_name' => 'Variance Customer Co.',
            'email' => 'variance@example.com',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dieselId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-VAR-AI',
            'name' => 'AI Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $e10Id = DB::table('fuel_types')->insertGetId([
            'code' => 'E10-VAR-AI',
            'name' => 'AI E10',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'salesOfficer', 'inventoryOfficer', 'driver', 'garageId', 'customerId', 'dieselId', 'e10Id');
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function varianceData(array $records): void
    {
        [$matchedSaleId, $matchedItemId] = $this->saleWithItem($records, 'SLS-VAR-AI-MATCHED', 1000, 50, 'paid');
        $this->paymentForSale($records, $matchedSaleId, 'PAY-VAR-AI-MATCHED', 50000);
        $this->stockOutForSale($records, $matchedSaleId, $matchedItemId, 'STO-VAR-AI-MATCHED', 1000);

        $this->saleWithItem($records, 'SLS-VAR-AI-MISSING-STOCK', 1000, 40, 'unpaid');

        [$mismatchSaleId, $mismatchItemId] = $this->saleWithItem($records, 'SLS-VAR-AI-QTY-MISMATCH', 2000, 45, 'confirmed', $records['e10Id']);
        $this->stockOutForSale($records, $mismatchSaleId, $mismatchItemId, 'STO-VAR-AI-QTY-MISMATCH', 500, null, $records['e10Id']);

        [$duplicateSaleId, $duplicateItemId] = $this->saleWithItem($records, 'SLS-VAR-AI-DUPLICATE', 2000, 30, 'confirmed');
        $deliveryId = DB::table('deliveries')->insertGetId([
            'delivery_code' => 'DLV-VAR-AI-DUP',
            'sale_id' => $duplicateSaleId,
            'sale_item_id' => $duplicateItemId,
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['dieselId'],
            'source_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'scheduled_at' => '2026-09-02 08:00:00',
            'scheduled_quantity_liters' => 2000,
            'actual_quantity_liters' => null,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->stockOutForSale($records, $duplicateSaleId, $duplicateItemId, 'STO-VAR-AI-DUP-A', 1000, $deliveryId);
        $this->stockOutForSale($records, $duplicateSaleId, $duplicateItemId, 'STO-VAR-AI-DUP-B', 1000, $deliveryId);

        [$missingReceivableSaleId, $missingReceivableItemId] = $this->saleWithItem($records, 'SLS-VAR-AI-MISSING-REC', 750, 70, 'confirmed');
        DB::table('receivables')->where('sale_id', $missingReceivableSaleId)->delete();
        $this->stockOutForSale($records, $missingReceivableSaleId, $missingReceivableItemId, 'STO-VAR-AI-MISSING-REC', 750);

        [$partialSaleId, $partialItemId] = $this->saleWithItem($records, 'SLS-VAR-AI-PARTIAL-OK', 3000, 55, 'partially_paid');
        $this->paymentForSale($records, $partialSaleId, 'PAY-VAR-AI-PARTIAL-OK', 50000);
        $this->stockOutForSale($records, $partialSaleId, $partialItemId, 'STO-VAR-AI-PARTIAL-OK', 3000);
    }

    /**
     * @param  array<string, mixed>  $records
     * @return array{0: int, 1: int}
     */
    private function saleWithItem(array $records, string $code, float $quantity, float $unitPrice, string $status, ?int $fuelTypeId = null): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $code,
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-02',
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'cod',
            'status' => $status,
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $fuelTypeId ?? $records['dieselId'],
            'quantity_liters' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('receivables')->insert([
            'sale_id' => $saleId,
            'due_date' => '2026-09-30',
            'status' => $status === 'paid' ? 'clear' : 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$saleId, $saleItemId];
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function stockOutForSale(
        array $records,
        int $saleId,
        int $saleItemId,
        string $code,
        float $quantity,
        ?int $deliveryId = null,
        ?int $fuelTypeId = null
    ): void {
        DB::table('stock_outs')->insert([
            'stock_out_code' => $code,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $fuelTypeId ?? $records['dieselId'],
            'storage_location_id' => $records['garageId'],
            'delivery_id' => $deliveryId,
            'quantity_liters' => $quantity,
            'stock_out_at' => '2026-09-02 08:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function paymentForSale(array $records, int $saleId, string $code, float $amount): void
    {
        DB::table('payments')->insert([
            'payment_code' => $code,
            'sale_id' => $saleId,
            'payment_schedule_id' => null,
            'payment_date' => '2026-09-02',
            'amount' => $amount,
            'method' => 'bank_transfer',
            'reference_number' => $code.'-REF',
            'received_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function aiConfig(): array
    {
        return [
            'services.ai.provider' => 'groq',
            'services.ai.api_key' => 'test-groq-key',
            'services.ai.model' => 'openai/gpt-oss-20b',
            'services.ai.base_url' => 'https://api.groq.com/openai/v1',
            'services.ai.timeout' => 60,
        ];
    }
}
