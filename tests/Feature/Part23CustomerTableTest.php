<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

class Part23CustomerTableTest extends AuditTestCase
{
    use PurchaseFlows;

    public function test_admin_and_sales_can_create_and_edit_blank_names_without_changing_other_customers(): void
    {
        $a = $this->actors();
        $original = DB::table('customers')->where('id', $a['customer'])->first();
        foreach (['admin', 'sales'] as $role) {
            $prefix = $role === 'admin' ? 'admin' : 'sales-officer';
            foreach (['omitted', '', null, '   '] as $i => $name) {
                $data = ['company_name' => "Company $role $i", 'status' => 'active', 'payment_status' => 'paid', 'phone' => '09171234567', 'bank_name' => 'Test bank'];
                if ($name !== 'omitted') $data['name'] = $name;
                $this->actingAs($a[$role])->post(route("$prefix.sales.customers.store"), $data)->assertSessionHasNoErrors();
                $customer = DB::table('customers')->where('company_name', $data['company_name'])->first();
                $this->assertNotNull($customer);
                $this->assertSame('', $customer->name);
                $this->patch(route("$prefix.sales.customers.update", $customer->id), ['name' => 'Contact'] + $data)->assertSessionHasNoErrors();
                $this->patch(route("$prefix.sales.customers.update", $customer->id), ['name' => ''] + $data)->assertSessionHasNoErrors();
                $this->assertDatabaseHas('customers', ['id' => $customer->id, 'customer_code' => $customer->customer_code, 'name' => '', 'phone' => '09171234567', 'bank_name' => 'Test bank']);
                $page = $this->get(route("$prefix.sales.customers", ['search' => $customer->customer_code]))->assertOk();
                $row = $page->viewData('customers')->first();
                $this->assertSame('—', $row['cells'][1]);
                $this->assertSame('', $row['name']); // The edit input must never contain the display dash.
                $this->assertSame($data['company_name'], $row['company_name']);
                $this->assertSame($customer->customer_code, $row['customer_code']);
                $this->assertSame(1, $this->get(route("$prefix.sales.customers", ['search' => $data['company_name']]))->viewData('customers')->total());
            }
        }
        $this->assertEquals($original, DB::table('customers')->where('id', $a['customer'])->first());
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.customers.update', $a['customer']), ['company_name' => $original->company_name, 'status' => 'active', 'payment_status' => 'paid'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customers', ['id' => $a['customer'], 'name' => $original->name]);
    }

    public function test_name_remains_length_validated_and_company_stays_required_and_unique(): void
    {
        $a = $this->actors();
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.customers.store'), ['name' => str_repeat('a', 256), 'company_name' => '', 'status' => 'active', 'payment_status' => 'paid'])->assertSessionHasErrors(['name', 'company_name']);
        $this->post(route('sales-officer.sales.customers.store'), ['company_name' => 'Audit Co.', 'status' => 'active', 'payment_status' => 'paid'])->assertSessionHasErrors('company_name')->assertSessionDoesntHaveErrors('name');
        $this->actingAs($a['inventory'])->post(route('sales-officer.sales.customers.store'), ['company_name' => 'Forbidden', 'status' => 'active', 'payment_status' => 'paid'])->assertForbidden();
        $this->patch(route('sales-officer.sales.customers.update', $a['customer']), ['name' => ''])->assertForbidden();
        $this->get(route('admin.sales.customers'))->assertForbidden();
        $this->assertDatabaseHas('customers', ['id' => $a['customer'], 'name' => 'Audit Customer']);
    }

    public function test_blank_name_customer_supports_sales_delivery_payments_and_table_search(): void
    {
        $a = $this->actors();
        DB::table('customers')->where('id', $a['customer'])->update(['name' => '']);
        $line = $this->purchaseLine($a, 'DSL', 1000);
        $this->recordOrder($a, ['fuel_type_id' => $a['fuel'], 'purchase_item_id' => $line, 'quantity_liters' => 100, 'unit_price' => 60])->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        $this->deliverOrder($a, $sale)->assertSessionHasNoErrors();
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $sale), ['idempotency_key' => $this->uuid(), 'payment_date' => '2026-10-16', 'amount' => 1000, 'method' => 'bank_transfer', 'reference_number' => 'TEST-TRANSFER'])->assertSessionHasNoErrors();
        $snapshot = [];
        foreach (['customers', 'sales', 'payments', 'stock_outs', 'inventory_movements'] as $table) $snapshot[$table] = DB::table($table)->orderBy('id')->get();
        foreach (['admin' => 'admin', 'sales' => 'sales-officer'] as $role => $prefix) {
            $response = $this->actingAs($a[$role])->get(route("$prefix.sales", ['search' => 'Audit Co.', 'sort' => 'code', 'direction' => 'asc', 'delivery_status' => 'delivered']))->assertOk();
            $this->assertCount(1, $response->viewData('sales'));
            $cells = $response->viewData('sales')->first()['cells'];
            $this->assertSame('6,000.00', $cells[8]);
            $this->assertSame('1,000.00', $cells[9]);
            $this->assertSame('5,000.00', $cells[10]);
            $panel = $this->tabPanel($response->getContent(), 'receivables');
            $this->assertStringNotContainsString('Customer Name', $panel);
            $this->assertStringContainsString('<th>Company Name</th>', $panel);
            $this->assertTableAlignment($panel);
            $this->assertStringContainsString('CUS-AUD / Audit Co.', $response->getContent());
        }
        foreach (['admin' => 'admin', 'inventory' => 'inventory-officer'] as $role => $prefix) {
            $response = $this->actingAs($a[$role])->get(route("$prefix.inventory.stock-out", ['search' => 'Audit Co.', 'sort' => 'code', 'direction' => 'asc']))->assertOk();
            $this->assertCount(1, $response->viewData('stockOut'));
            $panel = $this->tabPanel($response->getContent(), 'stock-out');
            $this->assertStringNotContainsString('Customer Name', $panel);
            $this->assertStringContainsString('<th>Company Name</th>', $panel);
            $this->assertTableAlignment($panel);
        }
        foreach ($snapshot as $table => $rows) $this->assertEquals($rows, DB::table($table)->orderBy('id')->get(), $table);
        $this->assertEquals(900, $this->remaining($line));
    }

    public function test_customer_pagination_sort_and_form_keep_blank_names(): void
    {
        $a = $this->actors();
        for ($i = 0; $i < 12; $i++) DB::table('customers')->insert(['customer_code' => sprintf('BLANK-%02d', $i), 'name' => '', 'company_name' => sprintf('Blank Company %02d', $i), 'status' => 'active', 'payment_status' => 'paid', 'location' => 'Lipa']);
        foreach (['admin' => 'admin', 'sales' => 'sales-officer'] as $role => $prefix) {
            $response = $this->actingAs($a[$role])->get(route("$prefix.sales.customers", ['search' => 'Blank Company', 'status' => 'active', 'location' => 'Lipa', 'sort' => 'company', 'direction' => 'asc', 'per_page' => 10]))->assertOk();
            $rows = $response->viewData('customers');
            $this->assertSame(12, $rows->total());
            $this->assertCount(10, $rows);
            $this->assertSame('Blank Company 00', $rows->first()['company_name']);
            $this->assertCount(2, $this->get($rows->nextPageUrl())->assertOk()->viewData('customers'));
            $this->assertStringContainsString('<th>Name</th>', $response->getContent());
            preg_match_all('/<input\b[^>]*name="name"[^>]*>/', $response->getContent(), $inputs);
            $this->assertNotEmpty($inputs[0]);
            foreach ($inputs[0] as $input) $this->assertDoesNotMatchRegularExpression('/\srequired(?:\s|>)/', $input);
        }
    }


    private function assertTableAlignment(string $html): void
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($doc);
        $headers = $xpath->query('//table[1]/thead/tr/th')->length;
        foreach ($xpath->query('//table[1]/tbody/tr') as $row) $this->assertSame($headers, $xpath->query('./td', $row)->length);
    }
}
