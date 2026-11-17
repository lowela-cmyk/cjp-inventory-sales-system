<?php

namespace App\Services;

use App\Models\ImportBatch;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Chunked, conservative importer for CJP operational workbooks.
 *
 * The detector never manufactures foreign keys. Rows with unresolved references or missing required fields are
 * previewed as invalid and are not written during confirmation. Existing records are never updated by this service.
 */
class SpreadsheetImportService
{
    // Larger windows reduce PhpSpreadsheet workbook-open overhead while keeping peak memory bounded.
    public const BATCH_SIZE = 1000;

    /** @var array<string, array<string, int|object>>|null */
    private ?array $referenceCache = null;

    /** @var array<string, array<string, array<string, int>>> */
    private array $referenceOverrides = [];

    private array $importedRecords = [];

    private ?int $activeBatchId = null;

    /** @var array<string, array<string, list<string>>> */
    private const ALIASES = [
        'purchases' => [
            'purchase_code' => ['purchase id', 'purchase code', 'purchase no', 'purchase number', 'order id'],
            'purchase_date' => ['date', 'purchase date', 'transaction date', 'order date'],
            'fuel' => ['fuel', 'fuel type', 'product'],
            'depot' => ['depot', 'pickup depot', 'depot name'],
            'depot_code' => ['depot code', 'depot id'],
            'quantity_liters' => ['purchased l', 'purchased liters', 'quantity', 'qty', 'quantity liters', 'liters'],
            'unit_cost' => ['cost / liter', 'cost per liter', 'unit cost', 'price / liter', 'price per liter'],
            'line_total' => ['total cost', 'total', 'line total'],
            'payment_mode' => ['payment mode', 'payment method', 'mode'],
            'bank_name' => ['bank', 'bank name'],
            'receipt_reference' => ['receipt', 'receipt reference', 'receipt no'],
            'status' => ['purchase status', 'status'],
            'payment_status' => ['payment status', 'payment state'],
            'month' => ['month'],
        ],
        'hauling' => [
            'haul_code' => ['lift id', 'lift code', 'haul id', 'haul code'],
            'purchase_code' => ['purchase id', 'purchase code'],
            'dr_number' => ['dr number', 'dr no', 'delivery receipt'],
            'scheduled_at' => ['lift date', 'lifting date', 'scheduled date', 'date'],
            'depot' => ['pickup depot', 'depot', 'depot name'],
            'depot_code' => ['depot code', 'depot id'],
            'driver' => ['driver', 'driver name'],
            'truck_code' => ['truck id', 'truck code', 'plate number', 'truck'],
            'capacity_liters' => ['capacity', 'capacity liters'],
            'quantity_liters' => ['lifted', 'lifted quantity', 'quantity', 'liters'],
            'delivered_quantity_liters' => ['delivered quantity', 'delivered liters'],
            'on_truck_liters' => ['on truck', 'remaining on truck'],
            'status' => ['status', 'lift status'],
            'month' => ['month'],
        ],
        'trucks' => [
            'plate_number' => ['plate number', 'plate', 'registration'],
            'truck_code' => ['truck code', 'truck id', 'truck number'],
            'name' => ['name / description', 'name'],
            'description' => ['description'],
            'capacity_liters' => ['capacity', 'capacity liters'],
            'status' => ['status'],
        ],
        'sales' => [
            'sale_code' => ['order id', 'sale id', 'sale code', 'transaction id', 'column1'],
            'sales_order_number' => ['sales order no', 'sales order number', 'order no', 'so no'],
            'sale_date' => ['transaction date', 'sale date', 'date'],
            'delivery_type' => ['delivery type', 'order type'],
            'customer_name' => ['customer name', 'customer'],
            'company_name' => ['company name', 'company', 'customer company'],
            'fuel' => ['fuel', 'fuel type', 'product'],
            'quantity_liters' => ['qty', 'quantity', 'quantity liters', 'liters'],
            'unit_price' => ['price / liter', 'price per liter', 'unit price', 'selling price'],
            'line_total' => ['total', 'line total', 'total amount'],
            'total_paid' => ['total paid', 'paid', 'amount paid'],
            'payment_method' => ['payment method'],
            'payment_reference' => ['payment reference', 'payment reference number'],
            'balance' => ['balance', 'outstanding', 'amount due'],
            'due_date' => ['due date'],
            'latest_payment' => ['latest payment', 'last payment', 'payment date'],
            'delivery_status' => ['delivery status', 'delivery state'],
            // Part 23 #3: the delivery truck and driver, kept when they match an existing truck / driver account.
            'truck_code' => ['truck id', 'truck code', 'plate number', 'truck'],
            'driver' => ['driver', 'driver name'],
            'payment_status' => ['payment status', 'payment state'],
            'recorded_by' => ['recorded by', 'created by'],
            'last_updated_by' => ['last updated by', 'updated by'],
            'month' => ['month'],
        ],
        'depots' => [
            'depot_code' => ['depot code', 'depot id', 'code'],
            'name' => ['name', 'depot name'],
            'address' => ['address'],
            'contact_person' => ['contact person', 'contact'],
            'phone' => ['phone', 'contact number'],
            'status' => ['status'],
        ],
        'customers' => [
            'customer_code' => ['customer code', 'customer id', 'code'],
            'name' => ['name', 'customer name', 'contact name'],
            'company_name' => ['company name', 'company', 'customer', 'customers'],
            'location' => ['location', 'address'],
            'email' => ['email', 'email address'],
            'phone' => ['phone', 'contact number'],
            'bank_name' => ['bank', 'bank name'],
            'status' => ['status'],
        ],
        'lifting_schedules' => [
            'schedule_code' => ['lift id', 'lift code', 'schedule id', 'schedule code'],
            'depot' => ['pickup depot', 'depot', 'depot name'],
            'depot_code' => ['depot code', 'depot id'],
            'driver' => ['driver', 'driver name'],
            'truck_code' => ['truck id', 'truck code', 'truck'],
            'dr_number' => ['dr number', 'dr no', 'delivery receipt'],
            'scheduled_at' => ['lift date', 'lifting date', 'scheduled date', 'date'],
            'status' => ['status', 'lift status'],
        ],
    ];

    /** @var array<string, list<string>> */
    private const REQUIRED = [
        'purchases' => ['purchase_date', 'fuel', 'quantity_liters', 'unit_cost'],
        'hauling' => ['purchase_code', 'scheduled_at', 'driver', 'truck_code', 'quantity_liters'],
        'trucks' => ['plate_number', 'name', 'capacity_liters', 'status'],
        'sales' => ['sale_date', 'fuel', 'quantity_liters', 'unit_price'],
        'depots' => ['depot_code', 'name', 'status'],
        // Customer codes are required by the database but generated by the Customers module when users encode a customer.
        'customers' => ['name', 'company_name'],
        'lifting_schedules' => ['scheduled_at', 'driver', 'truck_code'],
    ];

    /** At least one field from each group must be present in the UI/import mapping. */
    private const REQUIRED_ALTERNATIVES = [
        'purchases' => [['depot', 'depot_code']],
        'sales' => [['company_name', 'customer_name']],
        'lifting_schedules' => [['depot', 'depot_code']],
    ];

    /** @var array<string, string> */
    private const LABELS = [
        'purchases' => 'Purchases',
        'hauling' => 'Hauling',
        'trucks' => 'Trucks',
        'sales' => 'Sales',
        'depots' => 'Depots',
        'customers' => 'Customers',
        'lifting_schedules' => 'Lifting Schedules',
    ];

    /** Exact worksheet rules prevent derived report tabs from being misclassified by matching headers alone. */
    private const WORKSHEET_RULES = [
        'inventory purchases' => ['category' => 'purchases'],
        'inventory stock in' => ['ignored' => 'Derived from Purchases; importing it separately would duplicate stock-in quantities.'],
        'inventory stock out' => ['ignored' => 'Derived from Sales and delivery workflow; importing it separately would duplicate stock-out quantities.'],
        'inventory depots' => ['category' => 'depots'],
        'inventory garage' => ['ignored' => 'Garage balances are derived from hauling, receipts, and sales; source-level garage lots are required for safe import.'],
        'ledger' => ['ignored' => 'Ledger is a calculated reconciliation view.'],
        'ledger transactions' => ['ignored' => 'Ledger transactions are a calculated reconciliation view.'],
        'lifting schedule' => ['category' => 'lifting_schedules'],
        'lifting hauled' => ['category' => 'hauling'],
        'lifting trucks' => ['category' => 'trucks'],
        'lifting garage' => ['ignored' => 'Garage balances are derived from hauling, receipts, and sales.'],
        'sales receivables' => ['category' => 'sales'],
        'sales customers' => ['category' => 'customers'],
        'provenance audit' => ['ignored' => 'Audit/provenance worksheet; it documents source decisions and is not operational data.'],
        'source exceptions' => ['ignored' => 'Audit worksheet; exception notes are not operational records.'],
    ];

    /**
     * Source-labelled depot references that do not safely identify one existing master depot.
     * They are created only during an approved import, with a null address and an exact source
     * name. They are deliberately not merged with an existing physical depot.
     */
    private const PROVISIONAL_DEPOT_ALIASES = [
        'FILOIL/ARROWHEAD' => 'DPR-PRV-001',
        'UNIOIL LEMERY/EDZELVOR' => 'DPR-PRV-002',
        'POWERFILL (SARIAYA)' => 'DPR-PRV-003',
        'FILOIL(CALACA)' => 'DPR-PRV-004',
    ];

    /** @var array<string, string> */
    private const IGNORED_HEADER_REASONS = [
        'action' => 'Action column',
        'actions' => 'Action column',
        'view' => 'Display-only column',
        'edit' => 'Action column',
        'delete' => 'Action column',
        'select' => 'Action column',
        'checkbox' => 'Action column',
        'row' => 'Display-only column',
        'row no' => 'Display-only column',
        'row number' => 'Display-only column',
        '#' => 'Display-only column',
        'calculated' => 'Calculated column',
        'computed' => 'Calculated column',
        'display' => 'Display-only column',
    ];

    /** These values are derived by CJP's business rules and are never written from a workbook by default. */
    private const AUTO_IGNORED_FIELDS = [
        'line_total' => 'Calculated column',
        'balance' => 'Calculated column',
        'month' => 'Display-only column',
        'recorded_by' => 'Display-only column',
        'last_updated_by' => 'Display-only column',
    ];

    /** Fields present in exports or calculated views, but not entered in the corresponding manual form. */
    private const AUTO_IGNORED_FIELDS_BY_CATEGORY = [
        'purchases' => [
            'receipt_reference' => 'Not entered in the purchase form',
        ],
        'hauling' => [
            'capacity_liters' => 'Read from the selected truck',
            'delivered_quantity_liters' => 'Calculated after delivery',
            'on_truck_liters' => 'Calculated after delivery',
        ],
    ];

    public function store(UploadedFile $file, int $userId): ImportBatch
    {
        \App\Support\ImportReadiness::assertReady();
        $extension = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($extension, ['xlsx', 'xls', 'csv'], true), 422, 'Only .xlsx, .xls and .csv files are supported.');

        $uuid = (string) Str::uuid();
        $path = $file->storeAs('imports', $uuid.'.'.$extension, 'local');
        $absolutePath = Storage::disk('local')->path($path);
        try {
            $plan = $this->analyze($absolutePath);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw new \RuntimeException('The file was uploaded but could not be analyzed. Verify that it is a readable Excel/CSV workbook and try again.', 0, $e);
        }

        return ImportBatch::create([
            'batch_uuid' => $uuid,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'file_hash' => hash_file('sha256', $absolutePath),
            'file_type' => $extension,
            'status' => 'preview',
            'workbook_summary' => $plan['workbook'],
            'mapping_summary' => $plan['mappings'],
            'validation_summary' => $plan['validation'],
            'created_by' => $userId,
        ]);
    }

    /** @return array{workbook: array<string, mixed>, mappings: array<string, mixed>, validation: array<string, mixed>} */
    public function analyze(string $path, array $overrides = []): array
    {
        \App\Support\ImportReadiness::assertReady();
        $this->referenceCache = null;
        $this->referenceOverrides = $overrides['_reference_overrides'] ?? [];
        $reader = $this->reader($path);
        $workbook = ['filename' => basename($path), 'worksheets' => [], 'total_rows' => 0, 'categories' => []];
        $mappings = [];

        // Pass 1 only inspects the small header window. Row data is not loaded until the combined scan below.
        foreach ($reader->listWorksheetInfo($path) as $sheetInfo) {
            $sheet = (string) $sheetInfo['worksheetName'];
            $highestRow = (int) $sheetInfo['totalRows'];
            $highestColumn = (string) $sheetInfo['lastColumnLetter'];
            $sheetRule = self::WORKSHEET_RULES[$this->normalize($sheet)] ?? null;
            // The generated template includes documentation for humans. It must never be treated
            // as an operational import sheet just because its reference table contains known field names.
            if (in_array($this->normalize($sheet), ['column reference', 'import reference'], true)) {
                $workbook['worksheets'][] = ['name' => $sheet, 'rows' => 0, 'category' => null, 'status' => 'ignored'];
                continue;
            }
            $headerRows = $this->readRows($path, $sheet, 1, min(20, $highestRow), $highestColumn);
            if (is_array($sheetRule) && isset($sheetRule['ignored'])) {
                $headers = [];
                foreach (($headerRows[0] ?? []) as $column => $value) {
                    if (! $this->blank($value)) $headers[(string) $column] = trim((string) $value);
                }
                $workbook['worksheets'][] = [
                    'name' => $sheet, 'rows' => max(0, $highestRow - 1), 'category' => null,
                    'status' => 'ignored', 'reason' => $sheetRule['ignored'], 'header_row' => 1,
                    'highest_row' => $highestRow, 'highest_column' => $highestColumn,
                    'headers' => $headers, 'ignored_columns' => $headers,
                ];
                $mappings[$sheet] = [
                    'category' => null, 'columns' => [], 'ignored_columns' => $headers,
                    'missing_fields' => [], 'needs_edit' => false, 'importable' => false,
                    'ignored_reason' => $sheetRule['ignored'],
                ];
                continue;
            }
            [$headerRow, $mapping, $category] = $this->detectSheet($sheet, $headerRows);

            if (is_array($sheetRule) && isset($sheetRule['category'])) {
                [$headerRow, $mapping] = $this->detectSheetForCategory($headerRows, $sheetRule['category']);
                $category = $sheetRule['category'];
            }

            if (isset($overrides[$sheet]['category'], $overrides[$sheet]['columns'])) {
                if (array_key_exists($overrides[$sheet]['category'], self::LABELS)) {
                    $category = $overrides[$sheet]['category'];
                }
                $mapping = array_filter($overrides[$sheet]['columns'], fn ($field): bool => $field === null || in_array($field, array_keys(self::ALIASES[$category] ?? []), true));
            }

            if ($category === null || $headerRow === null) {
                $workbook['worksheets'][] = ['name' => $sheet, 'rows' => 0, 'category' => null, 'status' => 'unrecognized'];
                continue;
            }

            $headers = [];
            foreach (($headerRows[$headerRow - 1] ?? []) as $column => $value) {
                if (! $this->blank($value)) $headers[(string) $column] = trim((string) $value);
            }
            // Keep unmapped headers in the staged plan so the correction modal can offer them as choices.
            $mapping = array_replace(array_fill_keys(array_keys($headers), null), $mapping);
            $autoIgnoredColumns = [];
            if (! array_key_exists($sheet, $overrides)) {
                $autoIgnoredFields = self::AUTO_IGNORED_FIELDS + (self::AUTO_IGNORED_FIELDS_BY_CATEGORY[$category] ?? []);
                foreach ($mapping as $column => $field) {
                    if ($field !== null && isset($autoIgnoredFields[$field])) {
                        $autoIgnoredColumns[$column] = $autoIgnoredFields[$field];
                        $mapping[$column] = null;
                    }
                }
            }
            $ignoredColumns = [];
            foreach ($headers as $column => $header) {
                if (($mapping[$column] ?? null) === null) {
                    $ignoredColumns[$column] = $autoIgnoredColumns[$column] ?? $this->ignoredColumnReason($header);
                }
            }

            $workbook['worksheets'][] = [
                'name' => $sheet,
                'category' => $category,
                'category_label' => self::LABELS[$category],
                'header_row' => $headerRow,
                'highest_row' => $highestRow,
                'highest_column' => $highestColumn,
                'headers' => $headers, 'column_samples' => [], 'ignored_columns' => $ignoredColumns,
                'rows' => 0, 'date_from' => null, 'date_to' => null, 'months' => [], 'samples' => [],
            ];
            $mappedFields = array_values(array_filter($mapping, fn ($field): bool => $field !== null));
            $implicitFields = [];
            if ($category === 'customers' && in_array('name', $mappedFields, true) && ! in_array('company_name', $mappedFields, true)) {
                $implicitFields[] = 'company_name';
            }
            $missingFields = $this->missingRequiredFields($category, array_merge($mappedFields, $implicitFields));
            $mappings[$sheet] = [
                'category' => $category,
                'columns' => $mapping,
                'ignored_columns' => $ignoredColumns,
                'implicit_fields' => $implicitFields,
                'missing_fields' => $missingFields,
                'needs_edit' => $missingFields !== [],
            ];
        }

        $refs = $this->referenceMaps();
        // Stage workbook masters once so transactions can be validated regardless of worksheet order.
        foreach (['depots', 'trucks', 'customers'] as $master) {
            foreach ($this->rowsFor($path, $workbook, $mappings, $master) as [, $row]) {
                if ($master === 'depots') {
                    if (! $this->blank($row['depot_code'] ?? null)) $refs['depots'][strtoupper(trim((string) $row['depot_code']))] ??= -1;
                    if (! $this->blank($row['name'] ?? null)) $refs['depots'][$this->normalize($row['name'])] ??= -1;
                } elseif ($master === 'trucks') {
                    if (! $this->blank($row['truck_code'] ?? null)) $refs['trucks'][strtoupper(trim((string) $row['truck_code']))] ??= -1;
                } else {
                    if (! $this->blank($row['customer_code'] ?? null)) $refs['customers'][strtoupper(trim((string) $row['customer_code']))] ??= -1;
                    foreach (['name', 'company_name'] as $field) if (! $this->blank($row[$field] ?? null)) $refs['customers'][$this->normalize($row[$field])] ??= -1;
                }
            }
        }
        foreach ($this->rowsFor($path, $workbook, $mappings, 'purchases') as [, $row]) {
            if (! $this->blank($row['purchase_code'] ?? null)) $refs['purchases'][strtoupper(trim((string) $row['purchase_code']))] ??= -1;
        }
        foreach (self::PROVISIONAL_DEPOT_ALIASES as $name => $code) {
            $refs['depots'][$this->normalize($name)] ??= -2;
        }
        $this->applyReferenceOverrides($refs, $this->referenceOverrides);

        // Pass 2 combines row counting, samples, date coverage, duplicate detection, and validation.
        $validation = ['categories' => [], 'worksheets' => [], 'reference_candidates' => [], 'historical_driver_names' => [], 'provisional_depots' => self::PROVISIONAL_DEPOT_ALIASES, 'warnings' => [], 'total_valid' => 0, 'total_duplicates' => 0, 'total_invalid' => 0, 'total_skipped' => 0];
        $seen = [];
        foreach (self::LABELS as $category => $label) {
            $validation['categories'][$category] = ['rows' => 0, 'valid' => 0, 'duplicates' => 0, 'invalid' => 0, 'skipped' => 0, 'samples' => [], 'issue_groups' => []];
        }
        foreach ($workbook['worksheets'] as $index => $sheetSummary) {
            $category = $sheetSummary['category'] ?? null;
            if ($category === null) continue;
            $validation['worksheets'][$sheetSummary['name']] = ['category' => $category, 'rows' => 0, 'valid' => 0, 'duplicates' => 0, 'invalid' => 0, 'issue_groups' => []];
            $months = [];
            $minDate = null;
            $maxDate = null;
            foreach ($this->rowsFromSheet($path, $mappings, $sheetSummary) as [$rowNumber, $mapped, $raw]) {
                $workbook['worksheets'][$index]['rows']++;
                $workbook['total_rows']++;
                $workbook['categories'][$category] = ($workbook['categories'][$category] ?? 0) + 1;
                $isSample = count($workbook['worksheets'][$index]['samples']) < 5;
                if ($isSample) $workbook['worksheets'][$index]['samples'][] = $mapped;
                if ($isSample) {
                    foreach (array_keys($mappings[$sheetSummary['name']]['columns'] ?? []) as $column) {
                        $workbook['worksheets'][$index]['column_samples'][$column][] = $this->clean($raw[$column] ?? null);
                    }
                }
                $date = $this->dateValue($mapped['purchase_date'] ?? $mapped['sale_date'] ?? $mapped['scheduled_at'] ?? null);
                if ($date !== null) {
                    $minDate = $minDate === null || $date < $minDate ? $date : $minDate;
                    $maxDate = $maxDate === null || $date > $maxDate ? $date : $maxDate;
                    $months[$date->format('Y-m')] = true;
                }
                $counts = &$validation['categories'][$category];
                $sheetCounts = &$validation['worksheets'][$sheetSummary['name']];
                $counts['rows']++;
                $sheetCounts['rows']++;
                [$state, $issues] = $this->validateRow($category, $mapped, $refs, $seen);
                if (in_array($category, ['hauling', 'lifting_schedules'], true)
                    && ! $this->blank($mapped['driver'] ?? null)
                    && ! isset($refs['users'][$this->normalize($mapped['driver'])])) {
                    $driverName = trim((string) $mapped['driver']);
                    $validation['historical_driver_names'][$driverName] = ($validation['historical_driver_names'][$driverName] ?? 0) + 1;
                }
                $bucket = $state === 'valid' ? 'valid' : ($state === 'duplicate' ? 'duplicates' : 'invalid');
                $counts[$bucket]++;
                $sheetCounts[$bucket]++;
                foreach ($issues as $issue) {
                    $counts['issue_groups'][$issue] = ($counts['issue_groups'][$issue] ?? 0) + 1;
                    $sheetCounts['issue_groups'][$issue] = ($sheetCounts['issue_groups'][$issue] ?? 0) + 1;
                }
                foreach ($this->referenceIssue($category, $mapped, $issues) as $candidate) {
                    $candidateKey = $candidate['category'].'|'.$candidate['field'].'|'.$candidate['value'];
                    if (! isset($validation['reference_candidates'][$candidateKey])) $validation['reference_candidates'][$candidateKey] = $candidate + ['count' => 0];
                    $validation['reference_candidates'][$candidateKey]['count']++;
                }
                if (count($counts['samples']) < 5) $counts['samples'][] = ['row' => $rowNumber, 'state' => $state, 'issues' => $issues, 'data' => $mapped];
                unset($counts);
                unset($sheetCounts);
            }
            $workbook['worksheets'][$index]['date_from'] = $minDate?->toDateString();
            $workbook['worksheets'][$index]['date_to'] = $maxDate?->toDateString();
            $workbook['worksheets'][$index]['months'] = array_keys($months);
        }
        foreach ($validation['categories'] as $counts) {
            $validation['total_valid'] += $counts['valid'];
            $validation['total_duplicates'] += $counts['duplicates'];
            $validation['total_invalid'] += $counts['invalid'];
        }
        if ($validation['historical_driver_names'] !== []) {
            $validation['warnings'][] = 'Historical driver names will be stored as operational text references; no user accounts will be created or assigned.';
        }
        $validation['warnings'][] = 'Four unresolved source depot labels are handled as provisional references with null addresses; existing depot masters are not merged.';
        foreach ($workbook['worksheets'] as $sheet) {
            if (($sheet['status'] ?? null) === 'ignored') {
                $validation['warnings'][] = $sheet['name'].': '.($sheet['reason'] ?? 'display-only worksheet; no records will be written from it.');
            }
        }
        $validation['total_skipped'] = $validation['total_duplicates'] + $validation['total_invalid'];

        return ['path' => $path, 'workbook' => $workbook, 'mappings' => $mappings, 'validation' => $validation];
    }

    /** @return array<string, mixed> */
    public function import(ImportBatch $batch, int $userId): array
    {
        \App\Support\ImportReadiness::assertReady();
        if ($batch->status !== 'preview') {
            throw new \RuntimeException('This import batch has already been processed or cancelled.');
        }

        $path = Storage::disk('local')->path($batch->stored_path);
        if (! is_file($path) || hash_file('sha256', $path) !== $batch->file_hash) {
            throw new \RuntimeException('The staged file is missing or has changed. Upload it again.');
        }

        $this->referenceCache = null;
        $this->importedRecords = [];
        $this->activeBatchId = (int) $batch->id;
        $plan = $this->analyze($path, $batch->mapping_summary ?? []);
        // Claim the preview atomically after analysis. Cancellation can win while analysis is running,
        // but it can never race into a partially committed import after this point.
        $claimed = DB::table('import_batches')->where('id', $batch->id)->where('status', 'preview')->update([
            'status' => 'processing', 'confirmed_by' => $userId, 'confirmed_at' => now(), 'updated_at' => now(),
        ]);
        if ($claimed !== 1) {
            throw new \RuntimeException('This import was cancelled or is already being processed.');
        }

        try {
            $result = DB::transaction(function () use ($plan, $userId, $batch): array {
                $created = array_fill_keys(array_keys(self::LABELS), 0);
                $skipped = array_fill_keys(array_keys(self::LABELS), 0);
                $errors = [];
                $refs = $this->referenceMaps();

                $this->importMasters($plan, $refs, $created, $skipped, $errors);
                $this->refreshReferenceMaps($refs);
                $this->importSchedules($plan, $refs, $created, $skipped, $errors, $userId);
                $this->refreshReferenceMaps($refs);
                $this->importPurchases($plan, $refs, $created, $skipped, $errors, $userId);
                $this->refreshReferenceMaps($refs);
                $this->importHauling($plan, $refs, $created, $skipped, $errors, $userId);
                $this->refreshReferenceMaps($refs);
                $this->importSales($plan, $refs, $created, $skipped, $errors, $userId);

                $records = $this->importedRecords;
                $result = compact('created', 'skipped', 'errors', 'records');
                $batch->update(['status' => 'completed', 'validation_summary' => $plan['validation'], 'result_summary' => $result, 'completed_at' => now()]);

                return $result;
            });

            Storage::disk('local')->delete($batch->stored_path);

            return $result;
        } catch (Throwable $e) {
            $batch->update(['status' => 'failed', 'failure_message' => $e->getMessage(), 'completed_at' => now()]);
            Storage::disk('local')->delete($batch->stored_path);
            throw $e;
        }
    }

    /** @return list<string> */
    public function fieldsFor(string $category): array
    {
        return array_keys(self::ALIASES[$category] ?? []);
    }

    /** @return array<string, array{label:string, fields:list<array<string, mixed>>}> */
    public function importTemplateDefinition(): array
    {
        $headers = [
            'purchases' => [
                'purchase_code' => 'Purchase ID', 'purchase_date' => 'Date', 'fuel' => 'Fuel',
                'depot' => 'Depot', 'depot_code' => 'Depot Code', 'quantity_liters' => 'Purchased (L)',
                'unit_cost' => 'Cost / Liter', 'payment_mode' => 'Payment Mode', 'bank_name' => 'Bank',
                'payment_status' => 'Payment Status',
            ],
            'hauling' => [
                'haul_code' => 'Lift-ID', 'purchase_code' => 'Purchase-ID', 'dr_number' => 'DR Number',
                'scheduled_at' => 'Lift Date', 'depot' => 'Pickup Depot', 'depot_code' => 'Depot Code',
                'driver' => 'Driver', 'truck_code' => 'Truck-ID', 'quantity_liters' => 'Lifted',
            ],
            'trucks' => [
                'plate_number' => 'Plate Number', 'truck_code' => 'Truck Code', 'name' => 'Name / Description',
                'description' => 'Description', 'capacity_liters' => 'Capacity', 'status' => 'Status',
            ],
            'sales' => [
                'sale_code' => 'Order-ID', 'sales_order_number' => 'Sales Order No.', 'sale_date' => 'Transaction Date',
                'delivery_type' => 'Delivery Type', 'customer_name' => 'Customer Name', 'company_name' => 'Company Name',
                'fuel' => 'Fuel', 'quantity_liters' => 'QTY', 'unit_price' => 'Price / Liter',
                'payment_method' => 'Payment Method', 'payment_reference' => 'Payment Reference', 'latest_payment' => 'Payment Date',
                'total_paid' => 'Total Paid', 'due_date' => 'Due Date', 'delivery_status' => 'Delivery Status',
                'payment_status' => 'Payment Status',
            ],
            'depots' => [
                'depot_code' => 'Depot Code', 'name' => 'Name', 'address' => 'Address',
                'contact_person' => 'Contact Person', 'phone' => 'Phone', 'status' => 'Status',
            ],
            'customers' => [
                'customer_code' => 'Customer ID', 'name' => 'Name', 'company_name' => 'Company Name',
                'location' => 'Location', 'email' => 'Email', 'phone' => 'Contact Number',
                'bank_name' => 'Bank Name', 'status' => 'Status',
            ],
            'lifting_schedules' => [
                'schedule_code' => 'Lift-ID', 'dr_number' => 'DR Number', 'scheduled_at' => 'Lift Date',
                'depot' => 'Pickup Depot', 'depot_code' => 'Depot Code', 'driver' => 'Driver',
                'truck_code' => 'Truck-ID', 'status' => 'Status',
            ],
        ];
        $locations = [
            'purchases' => 'Inventory → Purchases table/form', 'hauling' => 'Fuel Lifting → Hauling table/form',
            'trucks' => 'Truck Management → Trucks table/form', 'sales' => 'Sales → Receivables table/form',
            'depots' => 'Inventory → Depots table/form', 'customers' => 'Sales → Customers table/form',
            'lifting_schedules' => 'Fuel Lifting → Schedule table/form',
        ];
        $types = [
            'purchase_date' => 'date', 'scheduled_at' => 'date', 'sale_date' => 'date', 'due_date' => 'date',
            'quantity_liters' => 'decimal', 'unit_cost' => 'decimal', 'unit_price' => 'decimal', 'total_paid' => 'decimal',
            'capacity_liters' => 'decimal',
        ];
        $generated = ['purchase_code', 'haul_code', 'truck_code', 'sale_code', 'customer_code'];
        $definitions = [];
        foreach (self::LABELS as $category => $label) {
            $ignored = self::AUTO_IGNORED_FIELDS + (self::AUTO_IGNORED_FIELDS_BY_CATEGORY[$category] ?? []);
            $fields = [];
            foreach ($this->fieldsFor($category) as $field) {
                if (isset($ignored[$field])) continue;
                $required = in_array($field, self::REQUIRED[$category] ?? [], true);
                $fields[] = [
                    'field' => $field,
                    'header' => $headers[$category][$field] ?? Str::headline($field),
                    'type' => $types[$field] ?? 'text',
                    'required' => $required,
                    'generated' => in_array($field, $generated, true),
                    'ui_location' => $locations[$category],
                    'example' => $this->templateExample($field),
                ];
            }
            $definitions[$category] = ['label' => $label, 'fields' => $fields];
        }

        return $definitions;
    }

    private function templateExample(string $field): string
    {
        return match ($field) {
            'purchase_date', 'scheduled_at', 'sale_date', 'due_date' => '2026-01-15',
            'quantity_liters', 'capacity_liters' => '10000',
            'unit_cost', 'unit_price' => '65.50',
            'total_paid' => '50000.00',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'SOURCE-REF-001',
            'latest_payment' => '2026-01-15',
            'status', 'payment_status', 'delivery_status' => 'Active',
            'payment_mode' => 'Bank Transfer',
            'fuel' => 'Diesel',
            'phone' => '09171234567',
            'email' => 'customer@example.com',
            default => 'Example value',
        };
    }

    /** @return array<string, list<array{id:int,label:string}>> */
    public function referenceOptions(): array
    {
        return [
            'customers' => DB::table('customers')->orderBy('name')->get(['id', 'name', 'company_name'])->map(fn ($row) => ['id' => (int) $row->id, 'label' => trim((string) ($row->company_name ?: $row->name))])->all(),
            'drivers' => DB::table('users')->whereIn('role', ['driver', 'admin'])->orderBy('name')->pluck('name', 'id')->map(fn ($name, $id) => ['id' => (int) $id, 'label' => (string) $name])->values()->all(),
            'trucks' => DB::table('trucks')->orderBy('truck_code')->get(['id', 'truck_code', 'plate_number'])->map(fn ($row) => ['id' => (int) $row->id, 'label' => trim((string) $row->truck_code.' '.(string) ($row->plate_number ?? ''))])->all(),
            'depots' => DB::table('depots')->orderBy('name')->get(['id', 'depot_code', 'name'])->map(fn ($row) => ['id' => (int) $row->id, 'label' => trim((string) $row->depot_code.' · '.(string) $row->name)])->all(),
            'fuel_types' => DB::table('fuel_types')->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($row) => ['id' => (int) $row->id, 'label' => trim((string) $row->code.' · '.(string) $row->name)])->all(),
        ];
    }

    /** @return array{0: int|null, 1: array<string, string|null>, 2: string|null} */
    private function detectSheet(string $sheet, array $rows): array
    {
        $best = [null, [], null, 0];
        foreach ($rows as $index => $row) {
            foreach (self::ALIASES as $category => $aliases) {
                $mapping = [];
                $score = 0;
                foreach ($row as $column => $value) {
                    $normalized = $this->normalize($value);
                    if ($normalized === '') {
                        continue;
                    }
                    $field = $this->fieldForHeader($category, $normalized);
                    if ($field !== null) {
                        $mapping[$column] = $field;
                        $score++;
                    }
                }
                if (strcasecmp($sheet, self::LABELS[$category]) === 0) {
                    $score += 2;
                }
                if ($score > $best[3]) {
                    $best = [$index + 1, $mapping, $category, $score];
                }
            }
        }

        if ($best[3] < 1) {
            return [null, [], null];
        }

        return [$best[0], $best[1], $best[2]];
    }

    /** @return array{0:int|null,1:array<string,string>} */
    private function detectSheetForCategory(array $rows, string $category): array
    {
        $bestRow = null;
        $bestMapping = [];
        $bestScore = 0;
        foreach ($rows as $index => $row) {
            $mapping = [];
            $score = 0;
            foreach ($row as $column => $value) {
                $field = $this->fieldForHeader($category, $this->normalize($value));
                if ($field !== null) {
                    $mapping[$column] = $field;
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestRow = $index + 1;
                $bestMapping = $mapping;
                $bestScore = $score;
            }
        }

        return [$bestRow, $bestMapping];
    }

    private function fieldForHeader(string $category, string $header): ?string
    {
        foreach (self::ALIASES[$category] as $field => $aliases) {
            foreach ($aliases as $alias) {
                if ($header === $this->normalize($alias)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /** @param list<string> $available @param array<string,mixed>|null $row @return list<string> */
    private function missingRequiredFields(string $category, array $available, ?array $row = null): array
    {
        $missing = [];
        foreach (self::REQUIRED[$category] ?? [] as $field) {
            if (! in_array($field, $available, true) || ($row !== null && $this->blank($row[$field] ?? null))) {
                $missing[] = $field;
            }
        }
        foreach (self::REQUIRED_ALTERNATIVES[$category] ?? [] as $group) {
            $present = array_values(array_intersect($group, $available));
            if ($present === []) {
                $missing[] = $group[0];
            } elseif ($row !== null && collect($present)->every(fn (string $field): bool => $this->blank($row[$field] ?? null))) {
                $missing[] = $group[0];
            }
        }

        return array_values(array_unique($missing));
    }

    private function ignoredColumnReason(string $header): string
    {
        $normalized = $this->normalize($header);
        foreach (self::IGNORED_HEADER_REASONS as $needle => $reason) {
            $normalizedNeedle = $this->normalize($needle);
            if ($normalizedNeedle !== '' && ($normalized === $normalizedNeedle || str_contains($normalized, $normalizedNeedle))) {
                return $reason;
            }
        }

        return 'Not used by this CJP import';
    }

    /** @param array<int|string, mixed> $row @param array<int|string, string|null> $mapping @return array<string, mixed> */
    private function mapRow(array $row, array $mapping, ?string $category = null): array
    {
        $mapped = [];
        foreach ($mapping as $column => $field) {
            if ($field !== null) {
                $mapped[$field] = $this->clean($row[$column] ?? null);
            }
        }

        // The reference Customers worksheet uses one NAME column for the customer/company label.
        // Manual customer encoding requires both values, so reuse that explicit source value for the
        // second required field instead of rejecting every customer or inventing a different value.
        if ($category === 'customers') {
            if ($this->blank($mapped['company_name'] ?? null) && ! $this->blank($mapped['name'] ?? null)) {
                $mapped['company_name'] = $mapped['name'];
            }
            if ($this->blank($mapped['name'] ?? null) && ! $this->blank($mapped['company_name'] ?? null)) {
                $mapped['name'] = $mapped['company_name'];
            }
        }

        return $mapped;
    }

    /** @param array<string, mixed> $row @param array<string, array<string, int|object>> $refs @param array<string, array<string, bool>> $seen @return array{0: string, 1: list<string>} */
    private function validateRow(string $category, array $row, array $refs, array &$seen): array
    {
        $issues = [];
        foreach ($this->missingRequiredFields($category, array_keys($row), $row) as $field) $issues[] = 'Missing '.$field;
        if ($issues !== []) {
            return ['invalid', $issues];
        }

        $keyField = match ($category) {
            'purchases' => 'purchase_code',
            'sales' => 'sale_code',
            'hauling' => 'haul_code',
            'trucks' => 'truck_code',
            'depots' => 'depot_code',
            'customers' => 'customer_code',
            'lifting_schedules' => 'schedule_code',
        };
        $key = in_array($category, ['customers', 'trucks'], true) && $this->blank($row[$keyField] ?? null)
            ? $this->normalize($category === 'customers' ? ($row['company_name'] ?? $row['name'] ?? null) : ($row['plate_number'] ?? $row['name'] ?? null))
            : strtoupper(trim((string) ($row[$keyField] ?? '')));
        if ($key === '') $key = $this->rowFingerprint($category, $row);
        if (DB::table('import_row_fingerprints')->where('fingerprint', $this->rowFingerprint($category, $row))->exists()) return ['duplicate', ['This source row was already imported']];
        $naturalKey = $category === 'customers' ? $this->normalize($row['company_name'] ?? null) : ($category === 'trucks' ? strtoupper(trim((string) ($row['plate_number'] ?? ''))) : '');
        if ($naturalKey !== '' && isset($refs[$category][$naturalKey]) && $refs[$category][$naturalKey] !== -1) return ['duplicate', ['Existing customer/company or truck plate']];
        $seen[$category] ??= [];
        $existingReference = $refs[$category][$key] ?? null;
        // During analysis, workbook master rows are staged with -1 so dependent rows can resolve
        // against them. That sentinel is not an existing database record and must not count as a duplicate.
        if (isset($seen[$category][$key]) || ($existingReference !== null && $existingReference !== -1)) {
            return ['duplicate', ['Duplicate '.$keyField.' '.$key]];
        }
        $seen[$category][$key] = true;

        foreach (['purchase_code', 'sale_code', 'haul_code', 'schedule_code', 'customer_code', 'depot_code', 'truck_code'] as $field) {
            if (mb_strlen((string) ($row[$field] ?? '')) > 30) $issues[] = $field.' must not exceed 30 characters';
        }
        foreach (['name', 'company_name', 'location', 'email', 'bank_name', 'payment_reference'] as $field) {
            if (mb_strlen((string) ($row[$field] ?? '')) > 255) $issues[] = $field.' must not exceed 255 characters';
        }
        if ($category === 'trucks' && $this->capacity($row['capacity_liters'] ?? null) <= 0) $issues[] = 'Truck capacity must be greater than zero';
        if ($category === 'trucks' && $this->capacity($row['capacity_liters'] ?? null) > TruckAvailabilityService::MAX_CAPACITY_LITERS) $issues[] = 'Truck capacity cannot exceed '.number_format(TruckAvailabilityService::MAX_CAPACITY_LITERS).' L';
        foreach (['quantity_liters', 'unit_cost', 'unit_price', 'total_paid', 'capacity_liters'] as $field) {
            if (! $this->blank($row[$field] ?? null)) {
                $value = str_replace([',', 'PHP', '₱', 'L'], '', (string) $row[$field]);
                if ($field === 'capacity_liters') $value = preg_replace('/kl/i', '', (string) $row[$field]);
                if (! is_numeric(trim($value)) || ! is_finite((float) $value) || (float) $value < 0) $issues[] = $field.' must be a non-negative number';
            }
        }
        foreach (['due_date', 'latest_payment'] as $field) {
            if (! $this->blank($row[$field] ?? null) && $this->dateValue($row[$field]) === null) $issues[] = $field.' is invalid';
        }
        $allowedStatuses = match ($category) {
            'customers', 'depots' => ['active', 'inactive'],
            'trucks' => ['available', 'assigned', 'in_use', 'loaded', 'maintenance', 'inactive'],
            'purchases' => ['draft', 'ordered', 'cancelled', 'canceled', 'completed', 'complete', 'hauled', 'partial', 'partially hauled', 'partially lifted', 'partially_hauled'],
            default => ['scheduled', 'lifted', 'in transit', 'in_transit', 'completed', 'complete', 'delivered', 'cancelled', 'canceled'],
        };
        if (! $this->blank($row['status'] ?? null) && ! in_array(mb_strtolower((string) $row['status']), $allowedStatuses, true)) $issues[] = 'Status is invalid';
        if ($category === 'purchases' && ! $this->blank($row['status'] ?? null) && ! in_array($this->purchaseStatus($row['status']), ['ordered', 'draft', 'cancelled'], true)) {
            $issues[] = 'Completed or partially lifted purchases require reviewed stock movements; import an open purchase and complete it through the workflow';
        }
        if (in_array($category, ['hauling', 'lifting_schedules'], true) && ! in_array($this->haulStatus($row['status'] ?? null), ['scheduled', 'cancelled'], true)) {
            $issues[] = 'Advanced lifting statuses require reviewed inventory movements; use the lifting workflow';
        }
        if ($category === 'hauling') {
            if (! isset($refs['purchases'][strtoupper(trim((string) ($row['purchase_code'] ?? '')))])) $issues[] = 'Purchase was not found';
            $quantity = $this->number($row['quantity_liters'] ?? null);
            if ($quantity <= 0) $issues[] = 'Quantity must be greater than zero';
            $purchaseId = $refs['purchases'][strtoupper(trim((string) ($row['purchase_code'] ?? '')))] ?? null;
            if ($purchaseId > 0) {
                $itemIds = DB::table('purchase_items')->where('purchase_id', $purchaseId)->pluck('id')->all();
                if (count($itemIds) !== 1) {
                    $issues[] = 'A multi-fuel purchase requires explicit line allocation through the lifting workflow';
                } else {
                    $line = app(PurchaseStockService::class)->lines($itemIds)->first();
                    $planned = $seen['_planned_lifts'][$purchaseId] ?? 0;
                    if (! $line || $quantity + $planned > (float) $line->remaining_in_depot + .005) $issues[] = 'Lift quantity exceeds the purchase stock remaining in the depot';
                    if ($issues === []) $seen['_planned_lifts'][$purchaseId] = $planned + $quantity;
                }
            }
            $truckId = $refs['trucks'][strtoupper(trim((string) ($row['truck_code'] ?? '')))] ?? null;
            if ($truckId > 0 && $quantity > (float) DB::table('trucks')->where('id', $truckId)->value('capacity_liters') + .005) $issues[] = 'Lift quantity exceeds the selected truck capacity';
        }
        if ($category === 'sales') {
            $delivery = mb_strtolower(trim((string) ($row['delivery_status'] ?? '')));
            if (! in_array($delivery, ['', 'pending'], true)) $issues[] = 'Delivered or in-transit sales require reviewed stock movements; use the delivery workflow';
            $type = mb_strtolower(trim((string) ($row['delivery_type'] ?? '')));
            if (! in_array($type, ['', 'standard', 'depot to client', 'depot_to_client'], true)) $issues[] = 'Delivery type is invalid or requires a stock source';
            if (! $this->blank($row['payment_status'] ?? null) && ! in_array(mb_strtolower((string) $row['payment_status']), ['paid', 'fully paid', 'partial', 'partially paid', 'partially_paid', 'unpaid', 'pending', 'clear'], true)) $issues[] = 'Payment status is invalid';
            $paymentLabel = mb_strtolower(trim((string) ($row['payment_status'] ?? '')));
            $amountPaid = $this->number($row['total_paid'] ?? 0);
            $calculatedTotal = round($this->number($row['quantity_liters'] ?? 0) * $this->number($row['unit_price'] ?? 0), 2);
            if (in_array($paymentLabel, ['paid', 'fully paid', 'clear'], true) && ($calculatedTotal <= 0 || abs($amountPaid - $calculatedTotal) > .005)) $issues[] = 'Paid status must match explicit payments for the full calculated sale total';
            if ($this->number($row['total_paid'] ?? 0) > 0) {
                if ($this->dateValue($row['latest_payment'] ?? null) === null || $this->blank($row['payment_reference'] ?? null) || ! in_array($row['payment_method'] ?? null, ['bank_transfer', 'cheque', 'cash_on_delivery'], true)) {
                    $issues[] = 'Paid amounts require an explicit payment date, method and reference; no payments will be inferred';
                }
            }
        }
        if ($category === 'purchases' && ! $this->blank($row['payment_status'] ?? null) && ! in_array(mb_strtolower((string) $row['payment_status']), ['paid', 'partial', 'unpaid'], true)) $issues[] = 'Payment status is invalid';

        if (in_array($category, ['purchases', 'sales'], true) && $this->number($row['quantity_liters'] ?? null) <= 0) {
            $issues[] = 'Quantity must be greater than zero';
        }
        if ($category === 'purchases' && $this->number($row['unit_cost'] ?? null) < 0) {
            $issues[] = 'Unit cost cannot be negative';
        }
        if ($category === 'sales' && $this->number($row['unit_price'] ?? null) < 0) {
            $issues[] = 'Unit price cannot be negative';
        }
        if ($category === 'purchases' && $this->lookupFuel($row['fuel'] ?? null) === null) {
            $issues[] = 'Fuel type does not match the configured catalog';
        }
        if ($category === 'purchases' && ! isset($refs['depots'][strtoupper(trim((string) ($row['depot_code'] ?? '')))] ) && ! isset($refs['depots'][$this->normalize($row['depot'] ?? null)])) {
            $issues[] = 'Depot was not found';
        }
        if (in_array($category, ['purchases', 'sales', 'hauling', 'lifting_schedules'], true)) {
            $dateField = $category === 'purchases' ? 'purchase_date' : ($category === 'sales' ? 'sale_date' : 'scheduled_at');
            if ($this->dateValue($row[$dateField] ?? null) === null) {
                $issues[] = 'Date is invalid';
            }
        }
        if ($category === 'sales' && $this->lookupFuel($row['fuel'] ?? null) === null) {
            $issues[] = 'Fuel type does not match the configured catalog';
        }
        // Historical hauling may reference a driver who never had a CJP login. A non-empty
        // name is retained in historical_driver_name; only a missing driver value is invalid.
        if ($category === 'hauling' && ! isset($refs['trucks'][strtoupper(trim((string) ($row['truck_code'] ?? '')))])) {
            $issues[] = 'Truck was not found';
        }
        if ($category === 'lifting_schedules') {
            $depotCode = strtoupper(trim((string) ($row['depot_code'] ?? '')));
            $depotName = $this->normalize($row['depot'] ?? null);
            if (! isset($refs['depots'][$depotCode]) && ! isset($refs['depots'][$depotName])) $issues[] = 'Depot was not found';
            $truckCode = strtoupper(trim((string) ($row['truck_code'] ?? null)));
            if (! isset($refs['trucks'][$truckCode])) $issues[] = 'Truck was not found';
            // A historical driver name is valid without a login account and is stored separately.
        }
        if ($category === 'sales' && ! isset($refs['customers'][strtoupper(trim((string) ($row['company_name'] ?? $row['customer_name'] ?? '')))]) && ! isset($refs['customers'][$this->normalize($row['company_name'] ?? $row['customer_name'] ?? null)])) {
            $issues[] = 'Customer/company was not found; import the customer master row first';
        }
        if ($category === 'sales' && $this->number($row['total_paid'] ?? 0) > $this->number($row['line_total'] ?? ($this->number($row['quantity_liters']) * $this->number($row['unit_price']))) + 0.01) {
            $issues[] = 'Total paid cannot exceed the calculated sale total';
        }

        return [$issues === [] ? 'valid' : 'invalid', $issues];
    }

    /** @param array<string, mixed> $plan @param array<string, array<string, int|object>> $refs @param array<string, int> $created @param array<string, int> $skipped @param list<string> $errors */
    private function importMasters(array $plan, array &$refs, array &$created, array &$skipped, array &$errors): void
    {
        foreach (['depots', 'trucks', 'customers'] as $category) {
            foreach ($this->rowsFor($plan['path'], $plan['workbook'], $plan['mappings'], $category) as [, $row]) {
                [$state] = $this->validateRow($category, $row, $refs, $refs['_seen']);
                if ($state !== 'valid') {
                    $skipped[$category]++;
                    continue;
                }
                try {
                    if ($category === 'depots') {
                        $id = DB::table('depots')->insertGetId([
                            'depot_code' => strtoupper(trim((string) $row['depot_code'])),
                            'name' => trim((string) $row['name']),
                            'address' => $this->nullable($row['address'] ?? null),
                            'contact_person' => $this->nullable($row['contact_person'] ?? null),
                            'phone' => $this->nullable($row['phone'] ?? null),
                            'status' => $this->enum($row['status'] ?? null, ['active', 'inactive'], 'active'),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $this->importedRecords[] = ['table' => 'depots', 'id' => $id];
                        $refs['depots'][strtoupper((string) $row['depot_code'])] = $id;
                    } elseif ($category === 'trucks') {
                        $truckCode = $this->blank($row['truck_code'] ?? null)
                            ? app(TruckAvailabilityService::class)->nextTruckCode()
                            : strtoupper(trim((string) $row['truck_code']));
                        $id = DB::table('trucks')->insertGetId([
                            'truck_code' => $truckCode,
                            'plate_number' => $this->nullable($row['plate_number'] ?? null),
                            'name' => $this->nullable($row['name'] ?? null),
                            'description' => $this->nullable($row['description'] ?? null),
                            'capacity_liters' => $this->capacity($row['capacity_liters']),
                            'truck_type' => null,
                            'status' => $this->enum($row['status'] ?? null, ['available', 'assigned', 'in_use', 'loaded', 'maintenance', 'inactive'], 'available'),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $this->importedRecords[] = ['table' => 'trucks', 'id' => $id];
                        $refs['trucks'][$truckCode] = $id;
                    } else {
                        // Customer codes are generated exactly as they are in the manual Customers form.
                        $id = DB::table('customers')->insertGetId([
                            'customer_code' => $this->customerCode($row['customer_code'] ?? null),
                            'name' => trim((string) ($row['name'] ?? $row['company_name'])),
                            'company_name' => trim((string) $row['company_name']),
                            'location' => $this->nullable($row['location'] ?? null),
                            'email' => $this->nullable($row['email'] ?? null),
                            'phone' => $this->nullable($row['phone'] ?? null),
                            'bank_name' => $this->nullable($row['bank_name'] ?? null),
                            'payment_status' => 'clear', 'status' => $this->enum($row['status'] ?? null, ['active', 'inactive'], 'active'),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $this->importedRecords[] = ['table' => 'customers', 'id' => $id];
                        if (! $this->blank($row['customer_code'] ?? null)) {
                            $refs['customers'][strtoupper((string) $row['customer_code'])] = $id;
                        }
                        $refs['customers'][$this->normalize($row['name'] ?? null)] = $id;
                        $refs['customers'][$this->normalize($row['company_name'] ?? null)] = $id;
                    }
                    $created[$category]++;
                } catch (Throwable $e) {
                    $errors[] = self::LABELS[$category].': '.$e->getMessage();
                    throw $e;
                }
            }
        }
        $usedProvisionalDepots = [];
        foreach (['purchases', 'hauling', 'lifting_schedules'] as $category) {
            foreach ($this->rowsFor($plan['path'], $plan['workbook'], $plan['mappings'], $category) as [, $row]) {
                $sourceName = trim((string) ($row['depot'] ?? ''));
                foreach (array_keys(self::PROVISIONAL_DEPOT_ALIASES) as $name) {
                    if ($sourceName !== '' && $this->normalize($sourceName) === $this->normalize($name)) {
                        $usedProvisionalDepots[$name] = true;
                    }
                }
            }
        }
        $this->ensureProvisionalDepots($refs, $created, array_keys($usedProvisionalDepots));
    }

    /** @param array<string, array<string, int|object>> $refs @param array<string, int> $created @param list<string> $names */
    private function ensureProvisionalDepots(array &$refs, array &$created, array $names): void
    {
        foreach (self::PROVISIONAL_DEPOT_ALIASES as $name => $code) {
            if (! in_array($name, $names, true)) {
                continue;
            }
            $key = $this->normalize($name);
            $override = $this->overrideId('depots', 'depot', $name);
            if ($override !== null) {
                $refs['depots'][$key] = $override;
                continue;
            }
            $existing = DB::table('depots')->where('depot_code', $code)->value('id');
            if ($existing === null) {
                $existing = DB::table('depots')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');
            }
            if ($existing === null) {
                $existing = DB::table('depots')->insertGetId([
                    'depot_code' => $code,
                    'name' => $name,
                    'address' => null,
                    'contact_person' => null,
                    'phone' => null,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created['depots']++;
            }
            $refs['depots'][$key] = (int) $existing;
            $refs['depots'][strtoupper($code)] = (int) $existing;
        }
    }

    /** @param array<string, mixed> $plan @param array<string, array<string, int|object>> $refs @param array<string, int> $created @param array<string, int> $skipped @param list<string> $errors */
    private function importSchedules(array $plan, array &$refs, array &$created, array &$skipped, array &$errors, int $userId): void
    {
        foreach ($this->rowsFor($plan['path'], $plan['workbook'], $plan['mappings'], 'lifting_schedules') as [$rowNumber, $row]) {
            [$state, $issues] = $this->validateRow('lifting_schedules', $row, $refs, $refs['_seen']);
            if ($state !== 'valid') {
                $skipped['lifting_schedules']++;
                if ($state === 'invalid') $errors[] = 'lifting_schedules row '.$rowNumber.': '.implode('; ', $issues);
                continue;
            }
            $depot = $this->lookupDepot($row);
            $truck = $this->lookupTruck($row['truck_code'] ?? null);
            $driver = $this->lookupUser($row['driver'] ?? null);
            $historicalDriver = $driver === null && ! $this->blank($row['driver'] ?? null)
                ? trim((string) $row['driver']) : null;
            $scheduled = $this->dateValue($row['scheduled_at'] ?? null);
            if (! $depot || ! $truck || ($driver === null && $historicalDriver === null) || ! $scheduled) {
                $skipped['lifting_schedules']++;
                continue;
            }
            $code = $this->blank($row['schedule_code'] ?? null)
                ? app(RecordCodeService::class)->next(RecordCodeService::LIFT, 0)
                : strtoupper(trim((string) $row['schedule_code']));
            if (DB::table('lifting_schedules')->where('schedule_code', $code)->exists()) {
                $skipped['lifting_schedules']++;
                continue;
            }
            $id = DB::table('lifting_schedules')->insertGetId([
                'schedule_code' => $code,
                'depot_id' => $depot,
                'truck_id' => $truck,
                'driver_user_id' => $driver,
                'historical_driver_name' => $historicalDriver,
                'dr_number' => $this->nullable($row['dr_number'] ?? null),
                'scheduled_at' => $scheduled,
                'status' => $this->haulStatus($row['status'] ?? null),
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->trackImportedRow('lifting_schedules', $row, ['table' => 'lifting_schedules', 'id' => $id]);
            $refs['lifting_schedules'][$code] = $id;
            $created['lifting_schedules']++;
        }
    }

    /** @param array<string, mixed> $plan @param array<string, array<string, int|object>> $refs @param array<string, int> $created @param array<string, int> $skipped @param list<string> $errors */
    private function importPurchases(array $plan, array &$refs, array &$created, array &$skipped, array &$errors, int $userId): void
    {
        $itemBatch = [];
        $historyBatch = [];
        foreach ($this->rowsFor($plan['path'], $plan['workbook'], $plan['mappings'], 'purchases') as [$rowNumber, $row]) {
            [$state, $issues] = $this->validateRow('purchases', $row, $refs, $refs['_seen']);
            if ($state !== 'valid') {
                $skipped['purchases']++;
                if ($state === 'invalid') $errors[] = 'purchases row '.$rowNumber.': '.implode('; ', $issues);
                continue;
            }
            $fuel = $this->lookupFuel($row['fuel'] ?? null);
            $depot = $this->lookupDepot($row);
            if (! $fuel || ! $depot || $this->number($row['quantity_liters'] ?? null) <= 0) {
                $skipped['purchases']++;
                continue;
            }
            $code = $this->blank($row['purchase_code'] ?? null)
                ? app(RecordCodeService::class)->next(RecordCodeService::PURCHASE, $fuel)
                : strtoupper(trim((string) $row['purchase_code']));
            if (DB::table('purchases')->where('purchase_code', $code)->exists()) {
                $skipped['purchases']++;
                continue;
            }
            $purchaseId = DB::table('purchases')->insertGetId([
                'purchase_code' => $code,
                'depot_id' => $depot,
                'purchase_date' => $this->dateValue($row['purchase_date'])->toDateString(),
                'receipt_reference' => $this->nullable($row['receipt_reference'] ?? null),
                'payment_status' => $this->enum($row['payment_status'] ?? null, ['paid', 'partial', 'unpaid'], 'unpaid'),
                // Part 23: store the canonical mode key and bank ("Bank (BDO)" -> bank_transfer / BDO).
                'payment_mode' => \App\Services\PurchaseService::normalizePaymentMode($row['payment_mode'] ?? null, $row['bank_name'] ?? null)[0],
                'bank_name' => \App\Services\PurchaseService::normalizePaymentMode($row['payment_mode'] ?? null, $row['bank_name'] ?? null)[1],
                'status' => $this->purchaseStatus($row['status'] ?? null),
                'workflow_status' => 'pending',
                'created_by' => $userId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->trackImportedRow('purchases', $row, ['table' => 'purchases', 'id' => $purchaseId]);
            $purchaseItem = [
                'purchase_id' => $purchaseId, 'fuel_type_id' => $fuel,
                'quantity_ordered_liters' => $this->number($row['quantity_liters']),
                'unit_cost' => $this->number($row['unit_cost']),
                'line_total' => round($this->number($row['quantity_liters']) * $this->number($row['unit_cost']), 2),
                'quantity_hauled_liters' => 0, 'status' => 'unlifted', 'created_at' => now(), 'updated_at' => now(),
            ];
            $itemBatch[] = $purchaseItem;
            $historyBatch[] = [
                'purchase_id' => $purchaseId, 'previous_status' => null, 'new_status' => 'pending',
                'changed_by' => $userId, 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($itemBatch) >= self::BATCH_SIZE) {
                DB::table('purchase_items')->insert($itemBatch);
                DB::table('purchase_status_histories')->insert($historyBatch);
                $itemBatch = [];
                $historyBatch = [];
            }
            $refs['purchases'][$code] = $purchaseId;
            $created['purchases']++;
        }
        if ($itemBatch !== []) {
            DB::table('purchase_items')->insert($itemBatch);
            DB::table('purchase_status_histories')->insert($historyBatch);
        }
        // Reconcile stock once after all purchase items are present, keeping the service's business rules intact.
        $newPurchaseIds = collect($this->importedRecords)->where('table', 'purchases')->pluck('id');
        foreach (DB::table('purchase_items')->whereIn('purchase_id', $newPurchaseIds)->pluck('id') as $id) app(StockInService::class)->sync((int) $id, $userId);
    }

    /** @param array<string, mixed> $plan @param array<string, array<string, int|object>> $refs @param array<string, int> $created @param array<string, int> $skipped @param list<string> $errors */
    private function importHauling(array $plan, array &$refs, array &$created, array &$skipped, array &$errors, int $userId): void
    {
        $haulBatch = [];
        $liftedPurchases = [];
        foreach ($this->rowsFor($plan['path'], $plan['workbook'], $plan['mappings'], 'hauling') as [$rowNumber, $row]) {
            [$state, $issues] = $this->validateRow('hauling', $row, $refs, $refs['_seen']);
            if ($state !== 'valid') {
                $skipped['hauling']++;
                if ($state === 'invalid') $errors[] = 'hauling row '.$rowNumber.': '.implode('; ', $issues);
                continue;
            }
            $purchaseCode = strtoupper(trim((string) ($row['purchase_code'] ?? '')));
            $fuel = $this->lookupFuelFromPurchase($purchaseCode);
            $purchaseItem = $this->purchaseItem($purchaseCode, $fuel);
            $truck = $this->lookupTruck($row['truck_code'] ?? null);
            $driver = $this->lookupUser($row['driver'] ?? null);
            $historicalDriver = $driver === null && ! $this->blank($row['driver'] ?? null)
                ? trim((string) $row['driver']) : null;
            if (! $purchaseItem || ! $truck || ($driver === null && $historicalDriver === null)) {
                $skipped['hauling']++;
                continue;
            }
            $code = $this->blank($row['haul_code'] ?? null)
                ? app(RecordCodeService::class)->next(RecordCodeService::LIFT, (int) $purchaseItem->fuel_type_id)
                : strtoupper(trim((string) $row['haul_code']));
            if (DB::table('hauls')->where('haul_code', $code)->exists()) {
                $skipped['hauling']++;
                continue;
            }
            $depot = $this->lookupDepot($row);
            if (! $depot) {
                $skipped['hauling']++;
                continue;
            }
            $scheduled = $this->dateValue($row['scheduled_at'] ?? null);
            if (! $scheduled) {
                $skipped['hauling']++;
                continue;
            }
            $scheduleId = DB::table('lifting_schedules')->where('schedule_code', $code)->value('id');
            if ($scheduleId === null) {
                $scheduleId = DB::table('lifting_schedules')->insertGetId([
                    'schedule_code' => $code, 'depot_id' => $depot, 'truck_id' => $truck, 'driver_user_id' => $driver,
                    'historical_driver_name' => $historicalDriver,
                    'dr_number' => $this->nullable($row['dr_number'] ?? null), 'scheduled_at' => $scheduled,
                    'status' => $this->haulStatus($row['status'] ?? null), 'created_by' => $userId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->importedRecords[] = ['table' => 'lifting_schedules', 'id' => $scheduleId];
            }
            $haulBatch[] = [
                'lifting_schedule_id' => $scheduleId, 'haul_code' => $code, 'purchase_id' => $purchaseItem->purchase_id,
                'purchase_item_id' => $purchaseItem->id, 'depot_id' => $depot, 'fuel_type_id' => $purchaseItem->fuel_type_id,
                'truck_id' => $truck, 'driver_user_id' => $driver, 'historical_driver_name' => $historicalDriver,
                'dr_number' => $this->nullable($row['dr_number'] ?? null),
                'scheduled_at' => $scheduled, 'hauled_at' => $this->dateValue($row['scheduled_at'] ?? null),
                'source_location' => $this->nullable($row['depot'] ?? null), 'quantity_liters' => $this->number($row['quantity_liters']),
                'status' => $this->haulStatus($row['status'] ?? null), 'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($haulBatch) >= self::BATCH_SIZE) {
                DB::table('hauls')->insert($haulBatch);
                $haulBatch = [];
            }
            $this->trackImportedRow('hauling', $row, ['table' => 'hauls', 'code' => $code]);
            $liftedPurchases[(int) $purchaseItem->purchase_id] = true;
            $created['hauling']++;
        }
        if ($haulBatch !== []) {
            DB::table('hauls')->insert($haulBatch);
        }
        // Part 23 #15: an imported purchase takes the workflow status its imported lifts give it (Completed when fully
        // lifted and completed, Partially Lifted when short) instead of staying Pending. Derived from the rows only.
        foreach (array_keys($liftedPurchases) as $purchaseId) {
            app(PurchaseWorkflowService::class)->synchronize($purchaseId, $userId, null, false);
        }
    }

    /** @param array<string, mixed> $plan @param array<string, array<string, int|object>> $refs @param array<string, int> $created @param array<string, int> $skipped @param list<string> $errors */
    private function importSales(array $plan, array &$refs, array &$created, array &$skipped, array &$errors, int $userId): void
    {
        $itemBatch = [];
        $receivableBatch = [];
        $paymentBatch = [];
        $paymentNumber = ((int) DB::table('payments')->max('id')) + 1;
        foreach ($this->rowsFor($plan['path'], $plan['workbook'], $plan['mappings'], 'sales') as [$rowNumber, $row]) {
            [$state, $issues] = $this->validateRow('sales', $row, $refs, $refs['_seen']);
            if ($state !== 'valid') {
                $skipped['sales']++;
                if ($state === 'invalid') $errors[] = 'sales row '.$rowNumber.': '.implode('; ', $issues);
                continue;
            }
            $customer = $this->lookupCustomer($row['company_name'] ?? $row['customer_name'] ?? null);
            $fuel = $this->lookupFuel($row['fuel'] ?? null);
            $date = $this->dateValue($row['sale_date'] ?? null);
            if (! $customer || ! $fuel || ! $date || $this->number($row['quantity_liters'] ?? null) <= 0) {
                $skipped['sales']++;
                continue;
            }
            $code = $this->blank($row['sale_code'] ?? null)
                ? app(RecordCodeService::class)->next(RecordCodeService::SALE, $fuel)
                : strtoupper(trim((string) $row['sale_code']));
            if (DB::table('sales')->where('sale_code', $code)->exists()) {
                $skipped['sales']++;
                continue;
            }
            $total = round($this->number($row['quantity_liters']) * $this->number($row['unit_price']), 2);
            $paid = min($total, max(0, $this->number($row['total_paid'] ?? 0)));
            $status = $paid >= $total && $total > 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid');
            $delivery = $this->deliveryStatus($row['delivery_status'] ?? null);
            $saleId = DB::table('sales')->insertGetId([
                'sale_code' => $code, 'sales_order_number' => $this->nullable($row['sales_order_number'] ?? null),
                'delivery_type' => $this->deliveryType($row['delivery_type'] ?? null), 'customer_id' => $customer,
                'sale_date' => $date->toDateString(), 'payment_method' => $paid > 0 ? $row['payment_method'] : null,
                'payment_terms' => 'cod', 'status' => $status, 'delivery_status' => $delivery,
                'delivered_at' => $delivery === 'delivered' ? $date : null, 'created_by' => $userId,
                'delivery_truck_id' => $refs['trucks'][strtoupper(trim((string) ($row['truck_code'] ?? '')))] ?? null,
                'delivery_driver_user_id' => $this->blank($row['driver'] ?? null) ? null : ($refs['drivers'][$this->normalize($row['driver'])] ?? null),
                'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->trackImportedRow('sales', $row, ['table' => 'sales', 'id' => $saleId]);
            $itemBatch[] = [
                'sale_id' => $saleId, 'fuel_type_id' => $fuel, 'quantity_liters' => $this->number($row['quantity_liters']),
                'unit_price' => $this->number($row['unit_price']), 'f1_unit_price' => 0, 'f1_percentage' => 0,
                'main_quantity_liters' => $this->number($row['quantity_liters']), 'f1_quantity_liters' => 0,
                'line_total' => $total, 'fulfilled_quantity_liters' => $delivery === 'delivered' ? $this->number($row['quantity_liters']) : 0,
                'created_at' => now(), 'updated_at' => now(),
            ];
            $receivableBatch[] = ['sale_id' => $saleId, 'due_date' => $this->dateValue($row['due_date'] ?? null)?->toDateString(), 'status' => $paid >= $total ? 'clear' : ($paid > 0 ? 'partial' : 'unpaid'), 'created_at' => now(), 'updated_at' => now()];
            if ($paid > 0) {
                $paymentBatch[] = [
                    'payment_code' => 'PAY-IMP-'.str_pad((string) $paymentNumber++, 6, '0', STR_PAD_LEFT), 'sale_id' => $saleId, 'payment_date' => $this->dateValue($row['latest_payment'] ?? $row['sale_date'])->toDateString(),
                    'amount' => $paid, 'method' => $row['payment_method'], 'reference_number' => $row['payment_reference'], 'remarks' => 'Imported payment from explicit source values', 'received_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            if (count($itemBatch) >= self::BATCH_SIZE) {
                DB::table('sale_items')->insert($itemBatch);
                DB::table('receivables')->insert($receivableBatch);
                if ($paymentBatch !== []) {
                    DB::table('payments')->insert($paymentBatch);
                }
                $itemBatch = $receivableBatch = $paymentBatch = [];
            }
            $created['sales']++;
        }
        if ($itemBatch !== []) {
            DB::table('sale_items')->insert($itemBatch);
            DB::table('receivables')->insert($receivableBatch);
            if ($paymentBatch !== []) {
                DB::table('payments')->insert($paymentBatch);
            }
        }
    }

    private function rowFingerprint(string $category, array $row): string
    {
        $normalized = array_map(fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value, $row);
        ksort($normalized);

        return hash('sha256', $category.'|'.json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function trackImportedRow(string $category, array $row, array $record): void
    {
        DB::table('import_row_fingerprints')->insert(['fingerprint' => $this->rowFingerprint($category, $row), 'import_batch_id' => $this->activeBatchId, 'category' => $category, 'created_at' => now()]);
        $this->importedRecords[] = $record;
    }

    /** @return array<string, array<string, int|object>> */
    private function referenceMaps(): array
    {
        if ($this->referenceCache !== null) {
            return $this->referenceCache;
        }

        return $this->referenceCache = [
            'purchases' => DB::table('purchases')->pluck('id', 'purchase_code')->mapWithKeys(fn ($id, $key) => [strtoupper((string) $key) => (int) $id])->all(),
            'sales' => DB::table('sales')->pluck('id', 'sale_code')->mapWithKeys(fn ($id, $key) => [strtoupper((string) $key) => (int) $id])->all(),
            'hauling' => DB::table('hauls')->pluck('id', 'haul_code')->mapWithKeys(fn ($id, $key) => [strtoupper((string) $key) => (int) $id])->all(),
            'lifting_schedules' => DB::table('lifting_schedules')->pluck('id', 'schedule_code')->mapWithKeys(fn ($id, $key) => [strtoupper((string) $key) => (int) $id])->all(),
            'trucks' => DB::table('trucks')->get(['id', 'truck_code', 'plate_number'])->flatMap(fn ($r) => array_filter([strtoupper((string) $r->truck_code) => (int) $r->id, strtoupper((string) $r->plate_number) => (int) $r->id]))->all(),
            'depots' => DB::table('depots')->get(['id', 'depot_code', 'name'])->flatMap(fn ($r) => [strtoupper((string) $r->depot_code) => (int) $r->id, $this->normalize($r->name) => (int) $r->id])->all(),
            'customers' => DB::table('customers')->get(['id', 'customer_code', 'name', 'company_name'])->flatMap(fn ($r) => [strtoupper((string) $r->customer_code) => (int) $r->id, $this->normalize($r->name) => (int) $r->id, $this->normalize($r->company_name) => (int) $r->id])->all(),
            'fuel_types' => DB::table('fuel_types')->get(['id', 'code', 'name'])->flatMap(fn ($r) => [strtoupper((string) $r->code) => (int) $r->id, $this->normalize($r->name) => (int) $r->id])->all(),
            'users' => DB::table('users')->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [$this->normalize($name) => (int) $id])->all(),
            'drivers' => DB::table('users')->where('role', 'driver')->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [$this->normalize($name) => (int) $id])->all(),
            '_seen' => [],
        ];
    }

    /** @param array<string, array<string, int|object>> $refs */
    private function refreshReferenceMaps(array &$refs): void
    {
        $this->referenceCache = null;
        $fresh = $this->referenceMaps();
        foreach ($fresh as $key => $value) {
            $refs[$key] = $value;
        }
    }

    /** @return \Generator<int, array{0:int,1:array<string,mixed>,2:array<int|string,mixed>}> */
    private function rowsFor(string $path, array $workbook, array $mappings, string $category): \Generator
    {
        foreach ($workbook['worksheets'] as $sheet) {
            if (($sheet['category'] ?? null) !== $category) {
                continue;
            }
            yield from $this->rowsFromSheet($path, $mappings, $sheet);
        }
    }

    /** @return \Generator<int, array{0:int,1:array<string,mixed>}> */
    private function rowsFromSheet(string $path, array $mappings, array $sheetInfo): \Generator
    {
        $sheet = (string) $sheetInfo['name'];
        $headerRow = (int) $sheetInfo['header_row'];
        $mapping = $mappings[$sheet]['columns'] ?? [];
        $category = (string) ($sheetInfo['category'] ?? '');
        $highestRow = (int) ($sheetInfo['highest_row'] ?? $headerRow);
        $highestColumn = (string) ($sheetInfo['highest_column'] ?? 'A');
        for ($start = $headerRow + 1; $start <= $highestRow; $start += self::BATCH_SIZE) {
            foreach ($this->readRows($path, $sheet, $start, min($highestRow, $start + self::BATCH_SIZE - 1), $highestColumn) as $offset => $row) {
                if (! $this->isBlankRow($row)) {
                    yield [$start + $offset, $this->mapRow($row, $mapping, $category), $row];
                }
            }
        }
    }

    private function reader(string $path): \PhpOffice\PhpSpreadsheet\Reader\IReader
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader;
    }

    /** @return list<array<int, mixed>> */
    private function readRows(string $path, string $sheet, int $start, int $end, string $highestColumn): array
    {
        $reader = $this->reader($path);
        $reader->setLoadSheetsOnly([$sheet]);
        $reader->setReadFilter(new SpreadsheetChunkReadFilter($start, $end, $sheet));
        $book = $reader->load($path);
        $worksheet = $book->getSheetByName($sheet);
        if (! $worksheet) {
            return [];
        }
        $rows = [];
        for ($row = $start; $row <= $end; $row++) {
            $values = $worksheet->rangeToArray('A'.$row.':'.$highestColumn.$row, null, true, false, false)[0] ?? [];
            $rows[] = $values;
        }
        $book->disconnectWorksheets();
        unset($book);

        return $rows;
    }

    private function normalize(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = str_replace(['–', '—', '/', '\\', '(', ')', ':'], ' ', $value);

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $value));
    }

    private function customerCode(mixed $value): string
    {
        $code = trim((string) $value);
        if ($code !== '' && $code !== '-') {
            return strtoupper($code);
        }

        do {
            $code = 'CSM-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table('customers')->where('customer_code', $code)->exists());

        return $code;
    }

    private function clean(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '' || trim((string) $value) === '-';
    }

    private function isBlankRow(array $row): bool
    {
        return count(array_filter($row, fn ($value): bool => ! $this->blank($value))) === 0;
    }

    private function number(mixed $value): float
    {
        return (float) str_replace([',', 'L', 'PHP', '₱'], '', trim((string) $value));
    }

    private function capacity(mixed $value): float
    {
        $number = $this->number($value);

        return str_contains(mb_strtolower((string) $value), 'kl') ? $number * 1000 : $number;
    }

    private function dateValue(mixed $value): ?CarbonImmutable
    {
        if ($this->blank($value)) {
            return null;
        }
        if (is_numeric($value) && (float) $value > 20000) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[ T])/', (string) $value, $parts) && ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) return null;

        $parts = date_parse((string) $value);
        if ($parts['year'] === false || $parts['month'] === false || $parts['day'] === false || $parts['warning_count'] > 0 || $parts['error_count'] > 0) return null;

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function nullable(mixed $value): ?string
    {
        return $this->blank($value) ? null : trim((string) $value);
    }

    private function enum(mixed $value, array $allowed, string $fallback): string
    {
        $value = mb_strtolower(trim((string) $value));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function purchaseStatus(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return match ($value) {
            'completed', 'complete', 'hauled' => 'hauled',
            'cancelled', 'canceled' => 'cancelled',
            'partial', 'partially hauled', 'partially lifted' => 'partially_hauled',
            'draft' => 'draft',
            default => 'ordered',
        };
    }

    private function haulStatus(mixed $value): string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'completed', 'complete', 'delivered', 'completed (inferred)' => 'completed',
            'lifted' => 'lifted',
            'in transit', 'in_transit' => 'in_transit',
            'cancelled', 'canceled' => 'cancelled',
            default => 'scheduled',
        };
    }

    private function deliveryStatus(mixed $value): string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'delivered', 'complete', 'completed' => 'delivered',
            'out for delivery', 'out_for_delivery', 'in transit' => 'out_for_delivery',
            'cancelled', 'canceled' => 'cancelled',
            default => 'pending',
        };
    }

    private function deliveryType(mixed $value): string
    {
        return in_array(mb_strtolower(trim((string) $value)), ['depot to client', 'depot_to_client'], true) ? 'depot_to_client' : 'standard';
    }

    private function lookupFuel(mixed $value): ?int
    {
        if (($override = $this->overrideId('fuel_types', 'fuel', $value)) !== null) return $override;
        $map = $this->referenceMaps()['fuel_types'];
        $key = strtoupper(trim((string) $value));

        return $map[$key] ?? $map[$this->normalize($value)] ?? null;
    }

    private function lookupDepot(array $row): ?int
    {
        if (($override = $this->overrideId('depots', 'depot', $row['depot_code'] ?? $row['depot'] ?? null)) !== null) return $override;
        $map = $this->referenceMaps()['depots'];
        $code = strtoupper(trim((string) ($row['depot_code'] ?? '')));
        $name = $this->normalize($row['depot'] ?? null);

        return $map[$code] ?? $map[$name] ?? null;
    }

    private function lookupTruck(mixed $value): ?int
    {
        if (($override = $this->overrideId('trucks', 'truck_code', $value)) !== null) return $override;
        $map = $this->referenceMaps()['trucks'];
        $key = strtoupper(trim((string) $value));

        return $map[$key] ?? null;
    }

    private function lookupUser(mixed $value): ?int
    {
        if (($override = $this->overrideId('drivers', 'driver', $value)) !== null) return $override;
        return $this->referenceMaps()['users'][$this->normalize($value)] ?? null;
    }

    private function lookupCustomer(mixed $value): ?int
    {
        if (($override = $this->overrideId('customers', 'customer', $value)) !== null) return $override;
        $map = $this->referenceMaps()['customers'];
        $key = strtoupper(trim((string) $value));

        return $map[$key] ?? $map[$this->normalize($value)] ?? null;
    }

    private function lookupFuelFromPurchase(string $purchaseCode): ?int
    {
        $row = DB::table('purchase_items')->whereIn('purchase_id', function ($query) use ($purchaseCode): void {
            $query->select('id')->from('purchases')->where('purchase_code', $purchaseCode);
        })->first(['fuel_type_id']);

        return $row?->fuel_type_id ? (int) $row->fuel_type_id : null;
    }

    private function purchaseItem(string $purchaseCode, ?int $fuel): ?object
    {
        return DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.purchase_code', $purchaseCode)->when($fuel, fn ($q) => $q->where('purchase_items.fuel_type_id', $fuel))
            ->first(['purchase_items.id', 'purchase_items.purchase_id', 'purchase_items.fuel_type_id']);
    }

    private function nextPaymentCode(): string
    {
        $number = ((int) DB::table('payments')->max('id')) + 1;

        return 'PAY-IMP-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /** @param array<string, array<string, int|object>> $refs @param array<string, array<string, array<string, int>>> $overrides */
    private function applyReferenceOverrides(array &$refs, array $overrides): void
    {
        foreach ($overrides as $category => $fields) {
            foreach ($fields as $field => $values) {
                foreach ($values as $raw => $id) {
                    $key = in_array($field, ['depot', 'truck_code', 'fuel', 'customer'], true) ? strtoupper(trim((string) $raw) ) : $this->normalize($raw);
                    $map = match ($category) {
                        'customers' => 'customers', 'drivers' => 'users', 'trucks' => 'trucks', 'depots' => 'depots', 'fuel_types' => 'fuel_types', default => null,
                    };
                    if ($map !== null) $refs[$map][$key] = (int) $id;
                }
            }
        }
    }

    private function overrideId(string $category, string $field, mixed $value): ?int
    {
        foreach ($this->referenceOverrides[$category][$field] ?? [] as $raw => $id) {
            if (strtoupper(trim((string) $raw)) === strtoupper(trim((string) $value)) || $this->normalize($raw) === $this->normalize($value)) return (int) $id;
        }

        return null;
    }

    /** @return list<array{category:string,field:string,value:string}> */
    private function referenceIssue(string $category, array $row, array $issues): array
    {
        $result = [];
        $rules = [
            'Driver account was not found' => ['category' => 'drivers', 'field' => 'driver', 'value' => $row['driver'] ?? null],
            'Truck was not found' => ['category' => 'trucks', 'field' => 'truck_code', 'value' => $row['truck_code'] ?? null],
            'Customer/company was not found; import the customer master row first' => ['category' => 'customers', 'field' => 'customer', 'value' => $row['company_name'] ?? $row['customer_name'] ?? null],
            'Depot was not found' => ['category' => 'depots', 'field' => 'depot', 'value' => $row['depot_code'] ?? $row['depot'] ?? null],
            'Fuel type does not match the configured catalog' => ['category' => 'fuel_types', 'field' => 'fuel', 'value' => $row['fuel'] ?? null],
        ];
        foreach ($issues as $issue) if (isset($rules[$issue]) && ! $this->blank($rules[$issue]['value'])) $result[] = $rules[$issue] + ['value' => trim((string) $rules[$issue]['value'])];

        return $result;
    }
}

final class SpreadsheetChunkReadFilter implements IReadFilter
{
    public function __construct(private readonly int $start, private readonly int $end, private readonly string $sheet) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        // The CSV reader reports an empty worksheet name; XLSX/XLS readers report the selected sheet.
        return ($worksheetName === '' || $worksheetName === $this->sheet) && $row >= $this->start && $row <= $this->end;
    }
}
