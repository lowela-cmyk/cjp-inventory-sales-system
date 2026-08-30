<?php

namespace App\Http\Controllers;

use App\Services\InventoryLedgerService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryOfficerLedgerController extends Controller
{
    public function __invoke(Request $request, InventoryLedgerService $ledgerService, string $state = 'ledger'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $rows = $ledgerService->rows($search === '' ? null : $search);

        return view('inventory-officer.ledger', [
            'activeTab' => $state === 'transactions' ? 'transactions' : 'ledger',
            'search' => $search === '' ? null : $search,
            'ledger' => $rows['ledger'],
            'transactions' => $rows['transactions'],
            'latestBalances' => $rows['latestBalances'],
        ]);
    }
}
