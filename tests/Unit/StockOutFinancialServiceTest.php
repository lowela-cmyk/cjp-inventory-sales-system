<?php

namespace Tests\Unit;

use App\Services\StockOutFinancialService;
use PHPUnit\Framework\TestCase;

/**
 * System Analysis Part 4 (Stock Out tab): Total Cost = Quantity x Cost/Unit and Profit = Total Paid - Total Cost.
 */
class StockOutFinancialServiceTest extends TestCase
{
    public function test_the_word_file_example_100000_liters_at_60_sold_at_65(): void
    {
        $amounts = (new StockOutFinancialService)->calculate((object) [
            'quantity_liters' => 100000,
            'unit_price' => 65,
            'cost_unit' => 60,
            'sale_total' => 6500000,
            'sale_paid' => 6500000,
        ]);

        $this->assertSame(100000.0, $amounts['quantity']);
        $this->assertSame(60.0, $amounts['unit_cost']);
        $this->assertSame(6000000.0, $amounts['total_cost']);
        $this->assertSame(65.0, $amounts['unit_price']);
        $this->assertSame(6500000.0, $amounts['total_paid']);
        $this->assertSame(500000.0, $amounts['profit']);
    }
}
