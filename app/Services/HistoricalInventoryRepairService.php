<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Illuminate\Support\Facades\DB;

/**
 * Reconstructs the source workbook's derived inventory records without changing
 * purchases, sales, payments, users, or master transaction amounts.
 *
 * The source Stock-Out and Garage sheets are derived views. They are therefore
 * applied only through this explicit repair operation, never during normal file
 * import. Every inferred allocation is marked as a demonstration reconstruction.
 */
class HistoricalInventoryRepairService
{
    public function __construct(private readonly GarageTankService $garageTanks) {}

    /** @return array<string, mixed> */
    public function analyze(string $path): array
    {
        $stockOutRows = $this->stockOutRows($path);
        $garageRows = $this->garageRows($path);
        $purchaseCodes = DB::table('purchases')->pluck('id', 'purchase_code')->all();
        $sales = DB::table('sales')->pluck('id', 'sale_code')->all();

        $missingPurchases = [];
        $missingSales = [];
        foreach ($stockOutRows as $row) {
            $purchase = trim((string) ($row['purchase_code'] ?? ''));
            $sale = trim((string) ($row['sale_code'] ?? ''));
            if ($purchase !== '' && $purchase !== 'MULTIPLE FIFO LOTS' && ! isset($purchaseCodes[$purchase])) {
                $missingPurchases[$purchase] = ($missingPurchases[$purchase] ?? 0) + 1;
            }
            if ($sale !== '' && ! isset($sales[$sale])) {
                $missingSales[$sale] = ($missingSales[$sale] ?? 0) + 1;
            }
        }

        return [
            'stock_out_source_rows' => count($stockOutRows),
            'garage_source_rows' => count($garageRows),
            'missing_purchase_references' => $missingPurchases,
            'missing_sale_references' => $missingSales,
            'existing_stock_outs' => DB::table('stock_outs')->count(),
            'existing_inventory_movements' => DB::table('inventory_movements')->count(),
            'existing_garage_stocks' => DB::table('garage_stocks')->count(),
            'safe_to_apply' => $missingSales === [],
        ];
    }

    /** @return array<string, mixed> */
    public function repair(string $path, int $actorId, bool $dryRun = false): array
    {
        $stockOutRows = $this->stockOutRows($path);
        $garageRows = $this->garageRows($path);

        return DB::transaction(function () use ($stockOutRows, $garageRows, $actorId, $dryRun): array {
            if (! $dryRun) DB::table('purchase_items')->orderBy('id')->lockForUpdate()->pluck('id');
            $result = [
                'dry_run' => $dryRun,
                'stock_out_rows' => count($stockOutRows),
                'stock_out_rows_applied' => 0,
                'stock_out_allocations' => 0,
                'stock_out_skipped' => 0,
                'stock_out_unresolved' => [],
                'garage_tanks' => 0,
                'garage_lots' => 0,
                'garage_unresolved' => [],
            ];

            $plan = $this->planStockOuts($stockOutRows, $result);
            $garagePlan = $this->planGarage($garageRows, $result, $dryRun);

            if (! $dryRun) {
                foreach (array_chunk($plan['allocations'], 500) as $batch) {
                    DB::table('stock_outs')->insert(array_map(fn (array $allocation): array => $this->stockOutValues($allocation, $actorId), $batch));
                }
                foreach ($garagePlan['lots'] as $lot) {
                    $this->insertGarageLot($lot, $actorId);
                }
            }

            $result['stock_out_rows_applied'] = $plan['rows_applied'];
            $result['stock_out_allocations'] = count($plan['allocations']);
            $result['garage_tanks'] = $garagePlan['tank_count'] ?? 0;
            $result['garage_lots'] = count($garagePlan['lots']);

            return $result;
        });
    }

    /** @return array{allocations: array<int, array<string, mixed>>, rows_applied: int} */
    private function planStockOuts(array $rows, array &$result): array
    {
        $sales = DB::table('sales')->get()->keyBy('sale_code');
        $items = DB::table('sale_items')->get()->groupBy('sale_id');
        $fuelIds = DB::table('fuel_types')->pluck('id', 'code')->all();
        $purchases = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->orderBy('purchases.purchase_date')->orderBy('purchase_items.id')
            ->get(['purchase_items.*', 'purchases.purchase_code', 'purchases.purchase_date', 'purchases.depot_id', 'fuel_types.code as fuel_code'])
            ->groupBy('fuel_code');
        $used = PurchaseStockService::releasesByPurchaseQuery()->groupBy('purchase_item_id')->selectRaw('purchase_item_id, SUM(liters) AS liters')->pluck('liters', 'purchase_item_id')->all();
        $existingCodes = DB::table('stock_outs')->pluck('stock_out_code')->all();
        $existingRows = [];
        foreach ($existingCodes as $code) {
            if (preg_match('/^HSTO-(\d+)-/', $code, $match)) $existingRows[(int) $match[1]] = true;
        }
        $releasedByItem = DB::table('stock_outs')->where('status', 'released')->groupBy('sale_item_id')->selectRaw('sale_item_id, SUM(quantity_liters) AS liters')->pluck('liters', 'sale_item_id')->all();
        $allocations = [];
        $rowsApplied = 0;

        foreach ($rows as $row) {
            $sale = $sales->get(trim((string) ($row['sale_code'] ?? '')));
            $qty = round((float) ($row['quantity'] ?? 0), 2);
            $purchaseCode = trim((string) ($row['purchase_code'] ?? ''));
            if (! $sale || $qty <= 0) {
                $result['stock_out_unresolved'][] = ['row' => $row['source_row'], 'reason' => $sale ? 'quantity is missing or invalid' : 'sale was not found', 'sale' => $row['sale_code'] ?? null];
                continue;
            }
            $fuelId = $fuelIds[$row['fuel_code']] ?? null;
            $item = $items->get($sale->id, collect())->first(fn (object $candidate): bool => $fuelId !== null && (int) $candidate->fuel_type_id === (int) $fuelId);
            if (! $item) {
                $result['stock_out_unresolved'][] = ['row' => $row['source_row'], 'reason' => 'matching sale item was not found', 'sale' => $row['sale_code'] ?? null];
                continue;
            }
            $sourceCodePrefix = 'HSTO-'.str_pad((string) $row['source_row'], 6, '0', STR_PAD_LEFT).'-';
            if (isset($existingRows[$row['source_row']])) {
                $result['stock_out_skipped']++;
                continue;
            }
            if ($sale->delivery_status !== 'delivered' || abs((float) $item->quantity_liters - $qty) > 0.005 || (float) ($releasedByItem[$item->id] ?? 0) > 0) {
                $result['stock_out_unresolved'][] = ['row' => $row['source_row'], 'sale' => $row['sale_code'], 'reason' => 'delivery, quantity, or existing release conflicts with source'];
                continue;
            }

            $remaining = $qty;
            $candidates = $purchases->get((string) $row['fuel_code'], collect());
            if ($purchaseCode !== '' && $purchaseCode !== 'MULTIPLE FIFO LOTS') {
                $specified = $candidates->filter(fn (object $p): bool => (string) $p->purchase_code === $purchaseCode);
                if ($specified->isEmpty()) {
                    $result['stock_out_unresolved'][] = ['row' => $row['source_row'], 'reason' => 'source purchase reference was not found; no allocation invented', 'sale' => $row['sale_code'], 'purchase' => $purchaseCode, 'unallocated_liters' => $qty];
                    continue;
                }
                $candidates = $specified->concat($candidates->reject(fn (object $p): bool => (string) $p->purchase_code === $purchaseCode));
            }
            $rowAllocations = [];
            $rowUsed = [];
            foreach ($candidates as $purchase) {
                if ($remaining <= 0) break;
                if (substr((string) $purchase->purchase_date, 0, 10) > substr($row['date'], 0, 10)) continue;
                $available = round((float) $purchase->quantity_ordered_liters - (float) ($used[$purchase->id] ?? 0), 2);
                if ($available <= 0) continue;
                $liters = min($remaining, $available);
                $rowUsed[$purchase->id] = $liters;
                $rowAllocations[] = [
                    'source_row' => $row['source_row'],
                    'sale_id' => $sale->id,
                    'sale_item_id' => $item->id,
                    'customer_id' => $sale->customer_id,
                    'fuel_type_id' => $fuelId,
                    'purchase_item_id' => $purchase->id,
                    'depot_id' => $purchase->depot_id,
                    'quantity_liters' => $liters,
                    'unit_cost' => $purchase->unit_cost,
                    'stock_out_at' => $row['date'],
                    'part' => count($rowAllocations) + 1,
                ];
                $remaining = round($remaining - $liters, 2);
            }
            if ($remaining > 0) {
                $result['stock_out_unresolved'][] = ['row' => $row['source_row'], 'reason' => 'FIFO inventory was insufficient', 'sale' => $row['sale_code'], 'unallocated_liters' => $remaining];
                continue;
            }
            foreach ($rowUsed as $id => $liters) $used[$id] = round(($used[$id] ?? 0) + $liters, 2);
            array_push($allocations, ...$rowAllocations);
            $rowsApplied++;
        }

        return ['allocations' => $allocations, 'rows_applied' => $rowsApplied];
    }

    /** @return array<int, mixed> */
    private function planGarage(array $rows, array &$result, bool $dryRun): array
    {
        if (! $dryRun) {
            $this->garageTanks->ensureForActiveFuelTypes();
        }
        $fuelCodes = ['Tank DSL' => 'DSL', 'Tank UNL' => 'UNL', 'Tank PRM' => 'PREM', 'Tank F1' => 'F1'];
        $lots = [];
        $tankCount = 0;
        foreach ($rows as $row) {
            $fuelCode = $fuelCodes[$row['tank']] ?? null;
            $fuel = $fuelCode ? DB::table('fuel_types')->where('code', $fuelCode)->first() : null;
            if (! $fuel) {
                $result['garage_unresolved'][] = ['row' => $row['source_row'], 'reason' => 'fuel type not found', 'tank' => $row['tank']];
                continue;
            }
            $tank = DB::table('storage_locations')->where('type', 'garage')->where('fuel_type_id', $fuel->id)->where('tank_number', 1)->first();
            if (! $tank) {
                if (! $dryRun) {
                    $result['garage_unresolved'][] = ['row' => $row['source_row'], 'reason' => 'garage tank definition not found after provisioning', 'tank' => $row['tank']];
                    continue;
                }
                $tank = (object) ['id' => 0, 'capacity_liters' => $row['capacity']];
            }
            // Part 23 #4: every garage tank holds at most 24,000 L. The workbook's capacity column (50,000-120,000 L)
            // is not copied onto the tank; a larger value is capped at the standard tank size.
            $capacity = min((float) $row['capacity'] > 0 ? (float) $row['capacity'] : GarageTankService::TANK_CAPACITY_LITERS, GarageTankService::TANK_CAPACITY_LITERS);
            if (! $dryRun && (float) $tank->capacity_liters !== $capacity) {
                DB::table('storage_locations')->where('id', $tank->id)->update(['capacity_liters' => $capacity, 'updated_at' => now()]);
            }
            if (! DB::table('garage_stocks')->where('lot_code', 'like', 'HGL-'.$fuelCode.'-%')->exists()) {
                $lotStart = count($lots);
                $codes = array_filter(array_map('trim', explode(',', (string) $row['purchase_codes'])));
                $items = DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->whereIn('purchases.purchase_code', $codes)->where('purchase_items.fuel_type_id', $fuel->id)->orderBy('purchases.purchase_date')->get(['purchase_items.*']);
                $remaining = (float) $row['stock_quantity'];
                foreach ($items as $item) {
                    if ($remaining <= 0) break;
                    $liters = min($remaining, (float) $item->quantity_ordered_liters);
                    $haul = DB::table('hauls')->where('purchase_item_id', $item->id)->where('status', 'completed')->orderBy('hauled_at')->first();
                    if (! $haul) continue;
                    $lots[] = ['lot_code' => 'HGL-'.$fuelCode, 'tank_id' => $tank->id, 'purchase_item_id' => $item->id, 'fuel_type_id' => $fuel->id, 'haul_id' => $haul->id, 'liters' => $liters, 'unit_cost' => $item->unit_cost, 'unloaded_at' => $haul->hauled_at ?: $haul->scheduled_at, 'capacity_liters' => $row['capacity']];
                    $remaining -= $liters;
                }
                if ($remaining > 0) {
                    $lots = array_slice($lots, 0, $lotStart);
                    $result['garage_unresolved'][] = ['row' => $row['source_row'], 'reason' => 'listed purchases lack sufficient completed haul dependencies; no partial tank posted', 'tank' => $row['tank'], 'unallocated_liters' => round($remaining, 2)];
                }
            }
            $tankCount++;
        }
        return ['lots' => $lots, 'tank_count' => $tankCount];
    }

    private function stockOutValues(array $a, int $actorId): array
    {
        $code = 'HSTO-'.str_pad((string) $a['source_row'], 6, '0', STR_PAD_LEFT).'-'.$a['part'];
        return [
            'stock_out_code' => $code,
            'sale_id' => $a['sale_id'], 'sale_item_id' => $a['sale_item_id'], 'customer_id' => $a['customer_id'],
            'fuel_type_id' => $a['fuel_type_id'], 'portion' => 'main', 'storage_location_id' => null,
            'source_type' => 'depot', 'depot_id' => $a['depot_id'], 'haul_allocation_id' => null,
            'purchase_item_id' => $a['purchase_item_id'], 'inventory_movement_id' => null,
            'quantity_liters' => $a['quantity_liters'], 'unit_cost' => $a['unit_cost'], 'stock_out_at' => $a['stock_out_at'],
            'status' => 'released', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function insertGarageLot(array $lot, int $actorId): void
    {
        $allocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $lot['haul_id'], 'fuel_type_id' => $lot['fuel_type_id'], 'destination_type' => 'garage',
            'storage_location_id' => $lot['tank_id'], 'customer_id' => null, 'sale_id' => null,
            'quantity_liters' => $lot['liters'], 'f1_quantity_liters' => 0, 'allocated_at' => $lot['unloaded_at'],
            'status' => 'received', 'receive_on_completion' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $movementId = DB::table('inventory_movements')->insertGetId([
            'movement_code' => 'HMOV-IN-'.str_pad((string) $allocationId, 7, '0', STR_PAD_LEFT),
            'storage_location_id' => $lot['tank_id'], 'fuel_type_id' => $lot['fuel_type_id'], 'movement_type' => 'stock_in',
            'direction' => 'in', 'quantity_liters' => $lot['liters'], 'unit_cost' => $lot['unit_cost'],
            'reference_type' => 'haul_allocation', 'reference_id' => $allocationId, 'movement_date' => $lot['unloaded_at'],
            'remarks' => 'Historical demonstration garage reconstruction; lot split inferred from listed source purchases.',
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('garage_stocks')->insert([
            'lot_code' => $lot['lot_code'].'-'.$allocationId, 'storage_location_id' => $lot['tank_id'],
            'purchase_item_id' => $lot['purchase_item_id'], 'fuel_type_id' => $lot['fuel_type_id'], 'haul_id' => $lot['haul_id'],
            'haul_allocation_id' => $allocationId, 'truck_id' => DB::table('hauls')->where('id', $lot['haul_id'])->value('truck_id'),
            'liters' => $lot['liters'], 'unit_cost' => $lot['unit_cost'], 'unloaded_at' => $lot['unloaded_at'],
            'unloaded_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function stockOutRows(string $path): array
    {
        $rows = [];
        foreach ($this->sheetRows($path, 'Inventory - Stock Out', 'K') as $number => $row) {
            $date = $row['B'] ?? null;
            $rows[] = ['source_row' => $number, 'sale_code' => trim((string) ($row['A'] ?? '')), 'date' => $this->date($date), 'fuel_code' => $this->fuelCode($row['E'] ?? ''), 'purchase_code' => trim((string) ($row['F'] ?? '')), 'quantity' => (float) ($row['K'] ?? 0)];
        }
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function garageRows(string $path): array
    {
        $rows = [];
        foreach ($this->sheetRows($path, 'Inventory - Garage', 'F') as $number => $row) {
            $rows[] = ['source_row' => $number, 'tank' => trim((string) ($row['A'] ?? '')), 'capacity' => (float) ($row['B'] ?? 0), 'stock_quantity' => (float) ($row['C'] ?? 0), 'purchase_codes' => trim((string) ($row['E'] ?? ''))];
        }
        return $rows;
    }

    /** Keep spreadsheet cell objects bounded; release each chunk before reading the next. */
    private function sheetRows(string $path, string $name, string $lastColumn): \Generator
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx') {
            yield from $this->xlsxRows($path, $name);
            return;
        }
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$name]);
        $info = collect($reader->listWorksheetInfo($path))->firstWhere('worksheetName', $name);
        if (! $info) throw new \RuntimeException('Missing required worksheet: '.$name);
        for ($start = 2; $start <= $info['totalRows']; $start += 1000) {
            $end = min($start + 999, $info['totalRows']);
            $reader->setReadFilter(new class($start, $end) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function __construct(private int $start, private int $end) {}
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row >= $this->start && $row <= $this->end;
                }
            });
            $book = $reader->load($path);
            try {
                foreach ($book->getSheetByName($name)->rangeToArray('A'.$start.':'.$lastColumn.$end, null, false, false, true) as $number => $row) yield $number => $row;
            } finally {
                $book->disconnectWorksheets();
                unset($book);
            }
        }
    }

    /** Stream worksheet XML so shared strings and cells never become a workbook object graph. */
    private function xlsxRows(string $path, string $name): \Generator
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) throw new \RuntimeException('Cannot open workbook.');
        try {
            $book = simplexml_load_string($zip->getFromName('xl/workbook.xml'), \SimpleXMLElement::class, LIBXML_NONET);
            $id = null;
            foreach ($book->xpath('//*[local-name()="sheet"]') as $sheet) {
                if ((string) $sheet['name'] === $name) $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            }
            $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'), \SimpleXMLElement::class, LIBXML_NONET);
            $target = null;
            foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel) if ((string) $rel['Id'] === $id && (string) $rel['TargetMode'] !== 'External') $target = (string) $rel['Target'];
            if (! $target || str_contains($target, '..')) throw new \RuntimeException('Missing or unsupported worksheet: '.$name);
            $strings = [];
            $stream = $zip->getStream('xl/sharedStrings.xml');
            if ($stream) {
                $xml = \XMLReader::fromStream($stream, null, LIBXML_NONET);
                while ($xml->read()) if ($xml->nodeType === \XMLReader::ELEMENT && $xml->localName === 'si') {
                    $node = simplexml_load_string($xml->readOuterXml(), \SimpleXMLElement::class, LIBXML_NONET);
                    $strings[] = implode('', array_map('strval', $node->xpath('//*[local-name()="t"]')));
                }
                $xml->close();
                fclose($stream);
            }
            $stream = $zip->getStream(str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target);
            if (! $stream) throw new \RuntimeException('Cannot read worksheet: '.$name);
            $xml = \XMLReader::fromStream($stream, null, LIBXML_NONET);
            try {
                while ($xml->read()) if ($xml->nodeType === \XMLReader::ELEMENT && $xml->localName === 'row') {
                    $node = simplexml_load_string($xml->readOuterXml(), \SimpleXMLElement::class, LIBXML_NONET);
                    $number = (int) $node['r'];
                    if ($number < 2) continue;
                    $row = [];
                    foreach ($node->xpath('./*[local-name()="c"]') as $cell) {
                        $column = preg_replace('/[0-9]+/', '', (string) $cell['r']);
                        $value = (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '');
                        $row[$column] = match ((string) $cell['t']) {
                            's' => $strings[(int) $value] ?? '',
                            'inlineStr' => implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]'))),
                            default => $value,
                        };
                    }
                    yield $number => $row;
                }
            } finally {
                $xml->close();
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    private function fuelCode(mixed $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'diesel' => 'DSL', 'premium' => 'PREM', 'unleaded' => 'UNL', 'f1' => 'F1', default => strtoupper(trim((string) $value)),
        };
    }

    private function date(mixed $value): string
    {
        if (is_numeric($value)) return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateTimeString();
        return CarbonImmutable::parse((string) $value)->toDateTimeString();
    }
}
