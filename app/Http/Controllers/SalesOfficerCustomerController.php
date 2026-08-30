<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalesOfficerCustomerController extends Controller
{
    private const PAYMENT_STATUSES = ['clear', 'pending', 'partial', 'unpaid'];
    private const ACCOUNT_STATUSES = ['active', 'inactive'];

    public function index(Request $request, string $state = 'receivables'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));

        return view('sales-officer.sales', [
            'activeTab' => $state === 'customers' ? 'customers' : 'receivables',
            'search' => $search === '' ? null : $search,
            'sales' => $this->salesRows($search === '' ? null : $search),
            'customers' => $this->customerRows($search === '' ? null : $search),
            'paymentStatuses' => self::PAYMENT_STATUSES,
            'accountStatuses' => self::ACCOUNT_STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedCustomerData($request);

        try {
            DB::table('customers')->insert([
                'customer_code' => $this->nextCustomerCode(),
                'name' => $data['name'],
                'company_name' => $data['company_name'],
                'location' => $data['location'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'payment_status' => $data['payment_status'],
                'status' => $data['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            return back()
                ->withInput()
                ->withErrors(['company_name' => 'Customer record could not be saved. Please review the customer details and try again.']);
        }

        return redirect()
            ->route('sales-officer.sales.customers')
            ->with('status', 'Customer record created successfully.');
    }

    public function update(Request $request, int $customer): RedirectResponse
    {
        abort_unless(DB::table('customers')->where('id', $customer)->exists(), 404);

        $data = $this->validatedCustomerData($request, $customer);

        try {
            DB::table('customers')
                ->where('id', $customer)
                ->update([
                    'name' => $data['name'],
                    'company_name' => $data['company_name'],
                    'location' => $data['location'] ?? null,
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'payment_status' => $data['payment_status'],
                    'status' => $data['status'],
                    'updated_at' => now(),
                ]);
        } catch (QueryException) {
            return back()
                ->withInput()
                ->withErrors(['company_name' => 'Customer record could not be updated. Please review the customer details and try again.']);
        }

        return redirect()
            ->route('sales-officer.sales.customers')
            ->with('status', 'Customer record updated successfully.');
    }

    public function deactivate(int $customer): RedirectResponse
    {
        abort_unless(DB::table('customers')->where('id', $customer)->exists(), 404);

        try {
            DB::table('customers')
                ->where('id', $customer)
                ->update([
                    'status' => 'inactive',
                    'updated_at' => now(),
                ]);
        } catch (QueryException) {
            return back()
                ->withErrors(['customer' => 'Customer record could not be deactivated. Please try again.']);
        }

        return redirect()
            ->route('sales-officer.sales.customers')
            ->with('status', 'Customer record deactivated successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCustomerData(Request $request, ?int $customerId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('customers', 'company_name')->ignore($customerId),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\\-\\s]+$/'],
            'payment_status' => ['required', Rule::in(self::PAYMENT_STATUSES)],
            'status' => ['required', Rule::in(self::ACCOUNT_STATUSES)],
        ]);
    }

    private function customerRows(?string $search)
    {
        return DB::table('customers')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'customer_code',
                'name',
                'company_name',
                'location',
                'email',
                'phone',
                'payment_status',
                'status',
            ]))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'modal_id' => 'so-customer-edit-'.$row->id,
                'customer_code' => $row->customer_code,
                'name' => $row->name,
                'company_name' => $row->company_name,
                'location' => $row->location,
                'email' => $row->email,
                'phone' => $row->phone,
                'payment_status' => $row->payment_status,
                'status' => $row->status,
                'class' => $row->status === 'inactive' ? 'row-danger' : $this->rowClass($row->payment_status),
                'cells' => [
                    $row->customer_code,
                    $row->name,
                    $row->company_name,
                    $row->location ?: 'N/A',
                    $row->email ?: 'N/A',
                    $row->phone ?: 'N/A',
                    $this->label($row->payment_status),
                ],
                'details' => [
                    'Customer Name' => $row->name,
                    'Company Name' => $row->company_name,
                    'Location' => $row->location ?: 'N/A',
                    'Email' => $row->email ?: 'N/A',
                    'Contact Number' => $row->phone ?: 'N/A',
                    'Payment Status' => $this->label($row->payment_status),
                    'Account Status' => $this->label($row->status),
                    'Date Added' => $this->formatDateTime($row->created_at),
                    'Last Updated' => $this->formatDateTime($row->updated_at),
                    'Transactions' => $this->transactionSummary((int) $row->id),
                ],
            ]);
    }

    private function salesRows(?string $search)
    {
        $payments = DB::table('payments')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as total_paid')
            ->groupBy('sale_id');

        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->leftJoinSub($payments, 'payments_total', 'payments_total.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'sales.sale_code',
                'customers.name',
                'customers.company_name',
                'fuel_types.name',
                'sales.status',
                'receivables.status',
            ]))
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sale_items.id')
            ->get([
                'sale_items.quantity_liters',
                'sale_items.unit_price',
                'sale_items.line_total',
                'sales.sale_code',
                'sales.sale_date',
                'sales.status',
                'customers.name as customer_name',
                'customers.company_name',
                'fuel_types.name as fuel_name',
                'receivables.status as receivable_status',
                'payments_total.total_paid',
            ])
            ->map(function (object $row): array {
                $paid = (float) ($row->total_paid ?? 0);
                $balance = max(0, (float) $row->line_total - $paid);
                $status = $this->label($row->receivable_status ?: $row->status);

                return [
                    $row->sale_code,
                    $this->formatDate($row->sale_date),
                    $row->customer_name,
                    $row->company_name,
                    $row->fuel_name,
                    $this->formatNumber($row->quantity_liters),
                    $this->formatNumber($row->unit_price),
                    $this->formatNumber($row->line_total),
                    $this->formatNumber($paid),
                    $this->formatNumber($balance),
                    $status,
                    $this->rowClass($status),
                ];
            });
    }

    /**
     * @param array<int, string> $columns
     */
    private function search(Builder $query, string $term, array $columns): Builder
    {
        return $query->where(function (Builder $query) use ($term, $columns): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    private function transactionSummary(int $customerId): string
    {
        $sales = DB::table('sales')->where('customer_id', $customerId)->whereNull('deleted_at')->count();
        $deliveries = DB::table('deliveries')->where('customer_id', $customerId)->count();

        if ($sales === 0 && $deliveries === 0) {
            return 'No transaction records found';
        }

        return $sales.' sales / '.$deliveries.' deliveries';
    }

    private function nextCustomerCode(): string
    {
        $nextId = ((int) DB::table('customers')->max('id')) + 1;

        do {
            $code = 'CSM-'.str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);
            $nextId++;
        } while (DB::table('customers')->where('customer_code', $code)->exists());

        return $code;
    }

    private function rowClass(?string $status): string
    {
        return match (strtolower(str_replace(' ', '_', (string) $status))) {
            'unpaid' => 'row-danger',
            'pending', 'partial' => 'row-warning',
            default => '',
        };
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    private function formatDate(mixed $date): string
    {
        return $date ? date('n/j/Y', strtotime((string) $date)) : 'N/A';
    }

    private function formatDateTime(mixed $date): string
    {
        return $date ? date('n/j/Y h:i A', strtotime((string) $date)) : 'N/A';
    }

    private function formatNumber(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }
}
