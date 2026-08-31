<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminSalesReportController extends Controller
{
    private const VALID_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];

    private const PAYMENT_METHODS = [
        'cash_on_delivery' => 'COD Payments',
        'cheque' => 'Cheque Payments',
        'advance_payment' => 'Advance Payments',
        'bank_transfer' => 'Banking Payments',
    ];

    public function __invoke(Request $request): View
    {
        $filters = $this->validatedFilters($request);
        $report = $this->reportData($filters);

        return view('admin.reports', array_merge($report, [
            'filters' => $filters,
            'filterLabel' => $this->filterLabel($filters),
        ]));
    }

    public function export(Request $request): Response
    {
        $filters = $this->validatedFilters($request);
        $report = $this->reportData($filters);
        $filename = 'sales-report-'.now()->format('Ymd-His').'.csv';
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['CJP Southern Star OPC Sales Report']);
        fputcsv($handle, ['Date Range', $this->filterLabel($filters)]);
        fputcsv($handle, ['Generated At', now()->format('M d, Y h:i A')]);
        fputcsv($handle, []);
        fputcsv($handle, ['Summary']);
        foreach ($report['summary'] as $card) {
            fputcsv($handle, [$card['label'], $card['raw']]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Transactions']);
        fputcsv($handle, ['Reference', 'Date', 'Customer', 'Company', 'Items', 'Quantity Liters', 'Sale Total', 'Total Paid', 'Balance', 'Payment Status']);
        foreach ($report['transactions'] as $row) {
            fputcsv($handle, [
                $row['sale_code'],
                $row['sale_date'],
                $row['customer_name'],
                $row['company_name'],
                $row['items'],
                $row['quantity_liters_raw'],
                $row['sale_total_raw'],
                $row['paid_raw'],
                $row['balance_raw'],
                $row['status'],
            ]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Payment History']);
        fputcsv($handle, ['Payment Reference', 'Sale Reference', 'Payment Date', 'Customer', 'Method', 'Amount', 'Received By']);
        foreach ($report['paymentHistory'] as $row) {
            fputcsv($handle, [
                $row['payment_code'],
                $row['sale_code'],
                $row['payment_date'],
                $row['customer_name'],
                $row['method'],
                $row['amount_raw'],
                $row['received_by'],
            ]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Receivables']);
        fputcsv($handle, ['Customer', 'Reference', 'Sale Total', 'Total Paid', 'Balance', 'Status', 'Due Date']);
        foreach ($report['receivables'] as $row) {
            fputcsv($handle, [
                $row['customer_name'],
                $row['sale_code'],
                $row['sale_total_raw'],
                $row['paid_raw'],
                $row['balance_raw'],
                $row['status'],
                $row['due_date'] ?: 'N/A',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function reportData(array $filters): array
    {
        $saleRows = $this->saleRows($filters);
        $transactionCount = $saleRows->count();
        $totalSales = (float) $saleRows->sum('sale_total');
        $totalQuantity = (float) $saleRows->sum('total_quantity_liters');
        $totalPaid = (float) $saleRows->sum('total_paid');
        $outstanding = (float) $saleRows->sum(fn (object $row): float => max(0, (float) $row->sale_total - (float) $row->total_paid));
        $statusCounts = $saleRows->map(fn (object $row): string => $this->financialStatus((float) $row->sale_total, (float) $row->total_paid, $row->due_date))
            ->countBy();
        $paymentTotals = $this->paymentTotals($filters);

        return [
            'summary' => [
                ['label' => 'Total Sales', 'value' => $this->formatMoney($totalSales), 'raw' => number_format($totalSales, 2, '.', '')],
                ['label' => 'Transactions', 'value' => number_format($transactionCount), 'raw' => $transactionCount],
                ['label' => 'Quantity Sold', 'value' => $this->formatLiters($totalQuantity), 'raw' => number_format($totalQuantity, 2, '.', '')],
                ['label' => 'Payments Received', 'value' => $this->formatMoney($totalPaid), 'raw' => number_format($totalPaid, 2, '.', '')],
                ['label' => 'Outstanding Receivables', 'value' => $this->formatMoney($outstanding), 'raw' => number_format($outstanding, 2, '.', '')],
                ['label' => 'Fully Paid Sales', 'value' => number_format((int) ($statusCounts['Settled'] ?? 0)), 'raw' => (int) ($statusCounts['Settled'] ?? 0)],
                ['label' => 'Partially Paid Sales', 'value' => number_format((int) ($statusCounts['Partially Paid'] ?? 0)), 'raw' => (int) ($statusCounts['Partially Paid'] ?? 0)],
                ['label' => 'Unpaid Sales', 'value' => number_format((int) (($statusCounts['Unpaid'] ?? 0) + ($statusCounts['Overdue'] ?? 0))), 'raw' => (int) (($statusCounts['Unpaid'] ?? 0) + ($statusCounts['Overdue'] ?? 0))],
            ],
            'fuelBreakdown' => $this->fuelBreakdown($filters),
            'customerBreakdown' => $this->customerBreakdown($saleRows),
            'paymentBreakdown' => $paymentTotals,
            'paymentHistory' => $this->paymentHistory($filters),
            'receivables' => $this->receivables($saleRows),
            'transactions' => $this->transactions($saleRows),
            'salesTrend' => $this->salesTrend($saleRows),
            'revenueBars' => $this->bars([
                ['Sales', $totalSales, '#0d1424'],
                ['Paid', $totalPaid, '#238636'],
                ['Receivables', $outstanding, '#a7191d'],
            ]),
            'paymentBars' => $this->bars($paymentTotals->map(fn (array $row): array => [$row['label'], $row['total_raw'], $row['color']])->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['all', 'today', 'date', 'range', 'month', 'year'])],
            'date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'month' => ['nullable', 'date_format:Y-m'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $period = $validated['period'] ?? 'all';

        if ($period === 'date') {
            validator($validated, ['date' => ['required', 'date']])->validate();
        }

        if ($period === 'range') {
            validator($validated, [
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            ])->validate();
        }

        if ($period === 'month') {
            validator($validated, ['month' => ['required', 'date_format:Y-m']])->validate();
        }

        if ($period === 'year') {
            validator($validated, ['year' => ['required', 'integer', 'between:2000,2100']])->validate();
        }

        return [
            'period' => $period,
            'date' => $validated['date'] ?? now()->toDateString(),
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'month' => $validated['month'] ?? now()->format('Y-m'),
            'year' => (string) ($validated['year'] ?? now()->year),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function saleRows(array $filters)
    {
        $items = DB::table('sale_items')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->selectRaw('sale_items.sale_id, COUNT(*) as item_count, SUM(sale_items.quantity_liters) as total_quantity_liters, SUM(sale_items.line_total) as sale_total, MIN(fuel_types.name) as first_fuel_name')
            ->groupBy('sale_items.sale_id');

        $payments = DB::table('payments')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as total_paid, MAX(payment_date) as latest_payment_date')
            ->groupBy('sale_id');

        return DB::table('sales')
            ->joinSub($items, 'items_total', 'items_total.sale_id', '=', 'sales.id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->leftJoinSub($payments, 'payments_total', 'payments_total.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->tap(fn (Builder $query): Builder => $this->applyDateFilter($query, $filters, 'sales.sale_date'))
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->get([
                'sales.id',
                'sales.sale_code',
                'sales.sale_date',
                'sales.payment_method',
                'sales.payment_terms',
                'customers.name as customer_name',
                'customers.company_name',
                'items_total.item_count',
                'items_total.total_quantity_liters',
                'items_total.sale_total',
                'items_total.first_fuel_name',
                'receivables.due_date',
                'payments_total.total_paid',
                'payments_total.latest_payment_date',
            ])
            ->map(function (object $row): object {
                $row->sale_total = (float) $row->sale_total;
                $row->total_quantity_liters = (float) $row->total_quantity_liters;
                $row->total_paid = (float) ($row->total_paid ?? 0);
                $row->balance = max(0, $row->sale_total - $row->total_paid);
                $row->financial_status = $this->financialStatus($row->sale_total, $row->total_paid, $row->due_date);

                return $row;
            });
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function fuelBreakdown(array $filters)
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->tap(fn (Builder $query): Builder => $this->applyDateFilter($query, $filters, 'sales.sale_date'))
            ->selectRaw('fuel_types.name, COUNT(DISTINCT sales.id) as transactions, COALESCE(SUM(sale_items.quantity_liters), 0) as quantity, COALESCE(SUM(sale_items.line_total), 0) as total')
            ->groupBy('fuel_types.id', 'fuel_types.name')
            ->orderBy('fuel_types.name')
            ->get()
            ->map(fn (object $row): array => [
                'name' => $row->name,
                'transactions' => (int) $row->transactions,
                'quantity' => $this->formatLiters((float) $row->quantity),
                'total' => $this->formatMoney((float) $row->total),
                'quantity_raw' => number_format((float) $row->quantity, 2, '.', ''),
                'total_raw' => (float) $row->total,
            ]);
    }

    private function customerBreakdown($saleRows)
    {
        return $saleRows
            ->groupBy('customer_name')
            ->map(function ($rows, string $customer): array {
                $first = $rows->first();
                $sales = (float) $rows->sum('sale_total');
                $paid = (float) $rows->sum('total_paid');
                $balance = (float) $rows->sum('balance');

                return [
                    'customer' => $customer,
                    'company' => $first->company_name,
                    'transactions' => $rows->count(),
                    'sales' => $this->formatMoney($sales),
                    'paid' => $this->formatMoney($paid),
                    'balance' => $this->formatMoney($balance),
                    'sales_raw' => number_format($sales, 2, '.', ''),
                    'paid_raw' => number_format($paid, 2, '.', ''),
                    'balance_raw' => number_format($balance, 2, '.', ''),
                ];
            })
            ->values();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function paymentTotals(array $filters)
    {
        $sales = DB::table('sales')
            ->whereNull('deleted_at')
            ->whereIn('status', self::VALID_SALE_STATUSES)
            ->tap(fn (Builder $query): Builder => $this->applyDateFilter($query, $filters, 'sale_date'))
            ->select('id');

        $rows = DB::table('payments')
            ->joinSub($sales, 'filtered_sales', 'filtered_sales.id', '=', 'payments.sale_id')
            ->selectRaw('payments.method, COUNT(*) as payments_count, COALESCE(SUM(payments.amount), 0) as total')
            ->groupBy('payments.method')
            ->get()
            ->keyBy('method');

        $colors = ['#0d1424', '#d97d16', '#238636', '#a7191d'];
        $index = 0;

        return collect(self::PAYMENT_METHODS)
            ->map(function (string $label, string $method) use ($rows, $colors, &$index): array {
                $row = $rows[$method] ?? null;
                $color = $colors[$index % count($colors)];
                $index++;

                return [
                    'method' => $method,
                    'label' => $label,
                    'count' => (int) ($row->payments_count ?? 0),
                    'total' => $this->formatMoney((float) ($row->total ?? 0)),
                    'total_raw' => (float) ($row->total ?? 0),
                    'color' => $color,
                ];
            })
            ->values();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function paymentHistory(array $filters)
    {
        $sales = DB::table('sales')
            ->whereNull('deleted_at')
            ->whereIn('status', self::VALID_SALE_STATUSES)
            ->tap(fn (Builder $query): Builder => $this->applyDateFilter($query, $filters, 'sale_date'))
            ->select('id');

        return DB::table('payments')
            ->joinSub($sales, 'filtered_sales', 'filtered_sales.id', '=', 'payments.sale_id')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('users', 'users.id', '=', 'payments.received_by')
            ->orderByDesc('payments.payment_date')
            ->orderByDesc('payments.id')
            ->get([
                'payments.payment_code',
                'payments.payment_date',
                'payments.amount',
                'payments.method',
                'sales.sale_code',
                'customers.name as customer_name',
                'users.name as received_by',
            ])
            ->map(fn (object $row): array => [
                'payment_code' => $row->payment_code,
                'payment_date' => $this->formatDate($row->payment_date),
                'amount' => $this->formatMoney((float) $row->amount),
                'method' => $this->paymentMethodLabel($row->method),
                'sale_code' => $row->sale_code,
                'customer_name' => $row->customer_name,
                'received_by' => $row->received_by ?: 'N/A',
                'amount_raw' => number_format((float) $row->amount, 2, '.', ''),
            ]);
    }

    private function receivables($saleRows)
    {
        return $saleRows
            ->filter(fn (object $row): bool => $row->balance > 0)
            ->values()
            ->map(fn (object $row): array => [
                'sale_code' => $row->sale_code,
                'customer_name' => $row->customer_name,
                'sale_total' => $this->formatMoney($row->sale_total),
                'paid' => $this->formatMoney($row->total_paid),
                'balance' => $this->formatMoney($row->balance),
                'status' => $row->financial_status,
                'due_date' => $row->due_date,
                'sale_total_raw' => number_format($row->sale_total, 2, '.', ''),
                'paid_raw' => number_format($row->total_paid, 2, '.', ''),
                'balance_raw' => number_format($row->balance, 2, '.', ''),
            ]);
    }

    private function transactions($saleRows)
    {
        return $saleRows
            ->map(fn (object $row): array => [
                'sale_code' => $row->sale_code,
                'sale_date' => $this->formatDate($row->sale_date),
                'customer_name' => $row->customer_name,
                'company_name' => $row->company_name,
                'items' => $this->fuelSummary((string) $row->first_fuel_name, (int) $row->item_count),
                'quantity_liters' => $this->formatNumber($row->total_quantity_liters),
                'sale_total' => $this->formatMoney($row->sale_total),
                'paid' => $this->formatMoney($row->total_paid),
                'balance' => $this->formatMoney($row->balance),
                'status' => $row->financial_status,
                'latest_payment_date' => $row->latest_payment_date ? $this->formatDate($row->latest_payment_date) : 'N/A',
                'payment_method' => $this->paymentMethodLabel($row->payment_method),
                'quantity_liters_raw' => number_format($row->total_quantity_liters, 2, '.', ''),
                'sale_total_raw' => number_format($row->sale_total, 2, '.', ''),
                'paid_raw' => number_format($row->total_paid, 2, '.', ''),
                'balance_raw' => number_format($row->balance, 2, '.', ''),
            ]);
    }

    private function salesTrend($saleRows): array
    {
        $rows = $saleRows
            ->groupBy(fn (object $row): string => CarbonImmutable::parse($row->sale_date)->format('M d'))
            ->map(fn ($rows): float => (float) $rows->sum('sale_total'));

        return $this->bars($rows->map(fn (float $total, string $label): array => [$label, $total, '#0d1424'])->values()->all());
    }

    /**
     * @param array<int, array{0: string, 1: float|int, 2: string}> $rows
     * @return array<int, array{label: string, value: string, height: int, color: string}>
     */
    private function bars(array $rows): array
    {
        $max = max(1, ...array_map(fn (array $row): float => abs((float) $row[1]), $rows ?: [['', 0, '#0d1424']]));

        return array_map(fn (array $row): array => [
            'label' => $row[0],
            'value' => $this->formatMoney((float) $row[1]),
            'height' => (float) $row[1] === 0.0 ? 6 : max(6, (int) round((abs((float) $row[1]) / $max) * 120)),
            'color' => $row[2],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyDateFilter(Builder $query, array $filters, string $column): Builder
    {
        return match ($filters['period']) {
            'today' => $query->whereDate($column, now()->toDateString()),
            'date' => $query->whereDate($column, $filters['date']),
            'range' => $query->whereBetween($column, [$filters['start_date'], $filters['end_date']]),
            'month' => $query->whereYear($column, CarbonImmutable::parse($filters['month'].'-01')->year)
                ->whereMonth($column, CarbonImmutable::parse($filters['month'].'-01')->month),
            'year' => $query->whereYear($column, (int) $filters['year']),
            default => $query,
        };
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function filterLabel(array $filters): string
    {
        return match ($filters['period']) {
            'today' => 'Today ('.now()->format('M d, Y').')',
            'date' => CarbonImmutable::parse($filters['date'])->format('M d, Y'),
            'range' => CarbonImmutable::parse($filters['start_date'])->format('M d, Y').' to '.CarbonImmutable::parse($filters['end_date'])->format('M d, Y'),
            'month' => CarbonImmutable::parse($filters['month'].'-01')->format('F Y'),
            'year' => (string) $filters['year'],
            default => 'All Time',
        };
    }

    private function financialStatus(float $saleTotal, float $totalPaid, mixed $dueDate = null): string
    {
        if ($saleTotal > 0 && $totalPaid >= $saleTotal) {
            return 'Settled';
        }

        if ($dueDate && CarbonImmutable::parse($dueDate)->lt(now()->startOfDay())) {
            return 'Overdue';
        }

        return $totalPaid > 0 ? 'Partially Paid' : 'Unpaid';
    }

    private function fuelSummary(string $firstFuel, int $itemCount): string
    {
        return $itemCount > 1 ? $firstFuel.' + '.($itemCount - 1).' more' : $firstFuel;
    }

    private function paymentMethodLabel(?string $method): string
    {
        return self::PAYMENT_METHODS[$method] ?? ($method ? ucwords(str_replace('_', ' ', $method)) : 'N/A');
    }

    private function formatDate(mixed $date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('M d, Y') : 'N/A';
    }

    private function formatMoney(float $value): string
    {
        return 'PHP '.number_format($value, 2);
    }

    private function formatLiters(float $value): string
    {
        return number_format($value, 2).' L';
    }

    private function formatNumber(float $value): string
    {
        return number_format($value, 2);
    }
}
