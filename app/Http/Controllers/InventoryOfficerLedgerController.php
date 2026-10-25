<?php

namespace App\Http\Controllers;

use App\Services\InventoryLedgerService;
use App\Support\FilterDates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Inventory ledger for Inventory Officers, Dispatch Officers and Admin (one shared page).
 */
class InventoryOfficerLedgerController extends Controller
{
    public function __invoke(Request $request, InventoryLedgerService $ledgerService, string $state = 'ledger'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date'],
            'depot_id' => ['nullable', 'integer', Rule::exists('depots', 'id')],
            'fuel_type_id' => ['nullable', 'integer', Rule::exists('fuel_types', 'id')],
            'status' => ['nullable', Rule::in(array_keys(InventoryLedgerService::STATUSES))],
        ]);

        $activeTab = $state === 'transactions' ? 'transactions' : 'ledger';
        $search = trim((string) ($data['search'] ?? ''));
        $filters = [
            'date' => $data['date'] ?? null,
            'depot_id' => $data['depot_id'] ?? null,
            'fuel_type_id' => $data['fuel_type_id'] ?? null,
            // The Ledger tab only lists purchases with fuel left to lift, so status filters the Transaction tab.
            'status' => $activeTab === 'transactions' ? ($data['status'] ?? null) : null,
        ];
        $rows = $ledgerService->rows($search === '' ? null : $search, $filters);

        return view('inventory-officer.ledger', [
            'activeTab' => $activeTab,
            'search' => $search === '' ? null : $search,
            'filters' => $filters,
            'filterOptions' => [
                'depots' => DB::table('depots')->orderBy('name')->pluck('name', 'id'),
                'fuelTypes' => DB::table('fuel_types')
                    ->where('status', 'active')
                    ->whereIn('code', array_keys(config('fuels.approved', ['F1' => true, 'UNL' => true, 'DSL' => true, 'PREM' => true])))
                    ->orderBy('name')
                    ->pluck('name', 'id'),
                'statuses' => InventoryLedgerService::STATUSES,
                'purchaseDates' => FilterDates::distinct(DB::table('purchases')->whereNull('deleted_at'), 'purchase_date'),
            ],
            'ledger' => $rows['ledger'],
            'transactions' => $rows['transactions'],
            'latestBalances' => $rows['latestBalances'],
        ]);
    }
}
