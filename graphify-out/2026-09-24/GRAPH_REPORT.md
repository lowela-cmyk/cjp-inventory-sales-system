# Graph Report - cjp_inventory_sales  (2026-09-24)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1633 nodes · 3906 edges · 165 communities (95 shown, 70 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 119 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `47e4d479`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- SalesOfficerCustomerController
- AIDataPreparationService
- Illuminate\Support\Facades\Schema
- DashboardSummaryService
- InventoryOfficerPurchaseController
- InventoryLedgerService
- RoleBasedAccessControlTest
- Illuminate\Foundation\Testing\RefreshDatabase
- AdminMonitoringController
- Illuminate\Database\Query\Builder
- OperationalDataRepairService
- AdminSalesReportController
- .rule
- PurchaseService
- .__invoke
- TestCase
- SalesOfficerSalesManagementTest
- TruckAvailabilityService
- StockOutReleaseService
- devDependencies
- PasswordResetCodeMail
- User
- DashboardSummaryCardsTest
- InventoryOfficerStockOutTest
- DispatchDeliveryController
- DispatchLiftingStatusController
- WorkflowSmokeTestService
- InventoryOfficerStockInTest
- RoleAccessTestingTest
- SalesOfficerPaymentRecordingTest
- DatabaseIntegrityTest
- IntegrationTestingTest
- AIFeatureTestingTest
- AIServiceConfigurationTest
- CompleteWorkflowIntegrationTest
- FormValidationTest
- FunctionalTestingTest
- Illuminate\Http\Request
- scripts
- AIDataPreparationServiceTest
- AnalyticsTestingTest
- BusinessInsightsTest
- Phase2GuardrailsAndIdempotencyTest
- DriverDeliveryController
- PurchaseReceiptUploadTest
- DispatchLiftingStatusManagementTest
- app.js
- ConnectedLiftingWorkflowTest
- InventoryLedgerTest
- InventoryVarianceExplanationsTest
- SalesOfficerCustomerManagementTest
- Controller
- composer.json
- TurnoverReadinessTest
- FrontendBugFixesTest
- HaulTruckAssignmentTest
- InventoryOfficerPurchaseManagementTest
- Phase4SecurityHardeningTest
- RevenueInsightsTest
- SalesOfficerReceivablesTrackingTest
- AdminSalesReportsTest
- PurchaseReceiptStatusTest
- SalesTrendSummariesTest
- AppServiceProvider.php
- require-dev
- setup
- Illuminate\View\View
- PurchaseWorkflowService
- config
- RoleBasedAccessControlTest.php
- AdminMonitoringTest
- StockInService
- Phase3OperationalAcceptanceTest
- SalesInitialPaymentTest
- Illuminate\Database\QueryException
- InventoryCostService
- psr-4
- logging.php
- Not Ready Verdict
- MasterDataAndInventoryAccessTest
- GarageTankService
- require
- post-create-project-cmd
- StatusBadgeTest.php
- ExampleTest
- .index
- extra
- CJP Brand Logo
- admin.partials.lift-table
- database.php
- Current Stock-Out Fulfillment Model
- index.blade.php
- CarbonImmutable
- CarbonImmutable
- CarbonImmutable
- Closure
- GarageTankService
- PurchaseWorkflowService
- WorkflowAlertService
- Closure
- Robots Policy
- PurchaseService
- Laravel Framework
- StockOutFinancialService
- UploadedFile
- SaleCorrectionService
- AIServiceConfigurationTest.php
- DriverLiftingStatusController
- .activeInventoryBalancesByFuelQuery
- keywords

## God Nodes (most connected - your core abstractions)
1. `User` - 148 edges
2. `SalesOfficerCustomerController` - 74 edges
3. `DashboardSummaryService` - 68 edges
4. `InventoryOfficerPurchaseController` - 61 edges
5. `AdminMonitoringController` - 48 edges
6. `AIDataPreparationService` - 43 edges
7. `RoleBasedAccessControlTest` - 38 edges
8. `PurchaseService` - 27 edges
9. `SalesOfficerSalesManagementTest` - 27 edges
10. `AIService` - 26 edges

## Surprising Connections (you probably didn't know these)
- `SalesOfficerCustomerController` --references--> `SaleConfirmationService`  [EXTRACTED]
  app/Http/Controllers/SalesOfficerCustomerController.php → app/Services/SaleConfirmationService.php
- `SalesOfficerCustomerController` --references--> `SaleCorrectionService`  [EXTRACTED]
  app/Http/Controllers/SalesOfficerCustomerController.php → app/Services/SaleCorrectionService.php
- `AdminDashboardService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AdminDashboardService.php → app/Services/DashboardSummaryService.php
- `AIDataPreparationService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AIDataPreparationService.php → app/Services/DashboardSummaryService.php
- `OperationalDataRepairService` --references--> `GarageTankService`  [EXTRACTED]
  app/Services/OperationalDataRepairService.php → app/Services/GarageTankService.php

## Import Cycles
- None detected.

## Communities (165 total, 70 thin omitted)

### Community 0 - "SalesOfficerCustomerController"
Cohesion: 0.06
Nodes (4): Closure, SalesOfficerCustomerController, AdminDashboardService, Illuminate\Support\Collection

### Community 1 - "AIDataPreparationService"
Cohesion: 0.06
Nodes (6): AIDataPreparationService, AIService, BusinessInsightService, InventoryVarianceExplanationService, RevenueInsightService, SalesTrendSummaryService

### Community 2 - "Illuminate\Support\Facades\Schema"
Cohesion: 0.04
Nodes (8): nextLocationCode(), up(), backfillDirectDepotCosts(), backfillGarageCosts(), up(), Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 5 - "InventoryLedgerService"
Cohesion: 0.12
Nodes (9): EnsureUserHasRole, SecurityHeaders, InventoryLedgerService, Closure, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware, Illuminate\Support\Facades\Auth (+1 more)

### Community 7 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.17
Nodes (5): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Storage, Illuminate\Support\Str, TruckManagementTest

### Community 9 - "Illuminate\Database\Query\Builder"
Cohesion: 0.20
Nodes (4): StockOutFinancialService, Illuminate\Database\Query\Builder, Illuminate\Validation\Rules\Exists, Illuminate\Validation\ValidationException

### Community 10 - "OperationalDataRepairService"
Cohesion: 0.23
Nodes (5): OperationalDataRepairService, DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder, SaleConfirmationService

### Community 13 - "PurchaseService"
Cohesion: 0.15
Nodes (4): IdempotencyService, PurchaseService, PurchaseWorkflowService, WorkflowAlertService

### Community 14 - ".__invoke"
Cohesion: 0.21
Nodes (4): AdminBusinessInsightController, AdminRevenueInsightController, AdminSalesTrendSummaryController, Controller

### Community 15 - "TestCase"
Cohesion: 0.12
Nodes (10): Carbon\Carbon, Illuminate\Auth\Access\AuthorizationException, Illuminate\Foundation\Testing\TestCase, Illuminate\Http\Client\Response, Illuminate\Support\Facades\Config, Illuminate\Support\Facades\Http, Illuminate\Support\Facades\Validator, DeliveryWorkflowRemovalTest (+2 more)

### Community 17 - "TruckAvailabilityService"
Cohesion: 0.17
Nodes (3): HaulTruckAssignmentController, TruckController, TruckAvailabilityService

### Community 18 - "StockOutReleaseService"
Cohesion: 0.16
Nodes (5): SaleConfirmationService, Closure, IdempotencyService, InventoryCostService, StockOutReleaseService

### Community 19 - "devDependencies"
Cohesion: 0.09
Nodes (21): axios, chart.js, concurrently, laravel-vite-plugin, devDependencies, axios, chart.js, concurrently (+13 more)

### Community 20 - "PasswordResetCodeMail"
Cohesion: 0.36
Nodes (5): PasswordResetCodeMail, Illuminate\Bus\Queueable, Illuminate\Mail\Mailable, Illuminate\Queue\SerializesModels, self

### Community 21 - "User"
Cohesion: 0.08
Nodes (7): User, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, AdminUserManagementTest, FuelCatalogRegressionTest, Phase5PerformanceTest

### Community 37 - "Illuminate\Http\Request"
Cohesion: 0.15
Nodes (3): AuthController, Illuminate\Http\RedirectResponse, Illuminate\Http\Request

### Community 38 - "scripts"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 44 - "PurchaseReceiptUploadTest"
Cohesion: 0.36
Nodes (3): Illuminate\Http\UploadedFile, UploadedFile, PurchaseReceiptUploadTest

### Community 46 - "app.js"
Cohesion: 0.21
Nodes (5): closeModal(), exportVisibleTable(), openModal(), sortVisibleTable(), visibleTableFor()

### Community 51 - "Controller"
Cohesion: 0.31
Nodes (4): AdminDashboardController, AdminInventoryVarianceExplanationController, ApprovedFuelType, Controller

### Community 52 - "composer.json"
Cohesion: 0.18
Nodes (10): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+2 more)

### Community 53 - "TurnoverReadinessTest"
Cohesion: 0.20
Nodes (4): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Route, TurnoverReadinessTest

### Community 63 - "AppServiceProvider.php"
Cohesion: 0.29
Nodes (4): AppServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 64 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 65 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 66 - "Illuminate\View\View"
Cohesion: 0.21
Nodes (6): InventoryOfficerLedgerController, Carbon\CarbonImmutable, Illuminate\Http\Response, Illuminate\Validation\Rule, Illuminate\Validation\Rules\Password, Illuminate\View\View

### Community 68 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 69 - "RoleBasedAccessControlTest.php"
Cohesion: 0.18
Nodes (8): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Support\Carbon, Illuminate\Support\Facades\Hash, Illuminate\Support\Facades\Mail, PHPUnit\Framework\Attributes\DataProvider, static, Symfony\Component\Mailer\Exception\TransportException

### Community 71 - "StockInService"
Cohesion: 0.19
Nodes (4): IdempotencyService, PurchaseWorkflowService, WorkflowAlertService, StockInService

### Community 74 - "Illuminate\Database\QueryException"
Cohesion: 0.47
Nodes (3): IdempotencyService, Closure, Illuminate\Database\QueryException

### Community 76 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 77 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 78 - "Not Ready Verdict"
Cohesion: 0.40
Nodes (5): Live Operational Data Gap, Not Ready Verdict, CJP Southern Star OPC System and Audit Report, Final Turnover Gate, Turnover Readiness Checklist

### Community 81 - "require"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 82 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 86 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 87 - "CJP Brand Logo"
Cohesion: 0.67
Nodes (3): CJP Brand Logo, CJP Monogram, Green Star Symbol

### Community 159 - "SaleCorrectionService"
Cohesion: 0.28
Nodes (4): PurchaseService, StockOutFinancialService, SaleCorrectionService, TruckAvailabilityService

### Community 160 - "AIServiceConfigurationTest.php"
Cohesion: 0.29
Nodes (5): Illuminate\Http\Client\ConnectionException, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\Log, RuntimeException, Throwable

### Community 164 - "keywords"
Cohesion: 0.67
Nodes (3): keywords, framework, laravel

## Knowledge Gaps
- **67 isolated node(s):** `private`, `$schema`, `build`, `dev`, `type` (+62 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **70 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Work-memory lessons

**Preferred sources** — corroborated by past sessions; start here.
- `InventoryOfficerPurchaseController` (6× useful, score=5.435252193)
- `SalesOfficerCustomerController` (5× useful, score=4.836741305)
- `DispatchDeliveryController` (5× useful, score=4.625492279)
- `StockOutReleaseService` (4× useful, score=3.782273813)
- `modal.blade.php` (4× useful, score=3.73882893)
- `SalesOfficerSalesManagementTest` (3× useful, score=2.998332867)
- `AdminMonitoringController` (3× useful, score=2.88788376)
- `DriverDeliveryController` (3× useful, score=2.756506915)
- `InventoryLedgerService` (3× useful, score=2.609935503)
- `InventoryLedgerTest` (2× useful, score=1.869688875)

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User` to `AIDataPreparationService`, `RoleBasedAccessControlTest`, `Illuminate\Foundation\Testing\RefreshDatabase`, `TestCase`, `SalesOfficerSalesManagementTest`, `InventoryOfficerStockOutTest`, `InventoryOfficerStockInTest`, `DatabaseIntegrityTest`, `IntegrationTestingTest`, `CompleteWorkflowIntegrationTest`, `FormValidationTest`, `FunctionalTestingTest`, `AIDataPreparationServiceTest`, `AnalyticsTestingTest`, `BusinessInsightsTest`, `Phase2GuardrailsAndIdempotencyTest`, `PurchaseReceiptUploadTest`, `DispatchLiftingStatusManagementTest`, `ConnectedLiftingWorkflowTest`, `InventoryLedgerTest`, `InventoryVarianceExplanationsTest`, `Controller`, `FrontendBugFixesTest`, `HaulTruckAssignmentTest`, `RevenueInsightsTest`, `PurchaseReceiptStatusTest`, `SalesTrendSummariesTest`, `RoleBasedAccessControlTest.php`, `AdminMonitoringTest`, `Phase3OperationalAcceptanceTest`, `SalesInitialPaymentTest`, `MasterDataAndInventoryAccessTest`?**
  _High betweenness centrality (0.152) - this node is a cross-community bridge._
- **Why does `AIDataPreparationService` connect `AIDataPreparationService` to `DashboardSummaryService`, `TestCase`?**
  _High betweenness centrality (0.055) - this node is a cross-community bridge._
- **Why does `SalesOfficerCustomerController` connect `SalesOfficerCustomerController` to `.updateSale`, `Illuminate\View\View`, `Illuminate\Http\Request`, `Illuminate\Database\Query\Builder`, `StockOutReleaseService`, `Controller`, `SaleCorrectionService`?**
  _High betweenness centrality (0.045) - this node is a cross-community bridge._
- **Are the 85 inferred relationships involving `User` (e.g. with `.records()` and `.baseRecords()`) actually correct?**
  _`User` has 85 INFERRED edges - model-reasoned connections that need verification._
- **What connects `private`, `$schema`, `build` to the rest of the system?**
  _67 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `SalesOfficerCustomerController` be split into smaller, more focused modules?**
  _Cohesion score 0.061381074168797956 - nodes in this community are weakly interconnected._
- **Should `AIDataPreparationService` be split into smaller, more focused modules?**
  _Cohesion score 0.0554954954954955 - nodes in this community are weakly interconnected._