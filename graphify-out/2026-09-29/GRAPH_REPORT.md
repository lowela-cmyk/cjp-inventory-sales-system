# Graph Report - cjp_inventory_sales  (2026-09-29)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1634 nodes · 3907 edges · 160 communities (90 shown, 70 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 119 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `47e4d479`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Illuminate\Support\Facades\Schema
- AIDataPreparationService
- DashboardSummaryService
- SalesOfficerCustomerController
- AdminMonitoringController
- Illuminate\Http\Request
- User
- Illuminate\Foundation\Testing\RefreshDatabase
- RoleBasedAccessControlTest
- TestCase.php
- .rule
- InventoryOfficerPurchaseController
- User.php
- PurchaseService
- SalesOfficerSalesManagementTest
- AdminSalesReportController
- StockOutReleaseService
- devDependencies
- DashboardSummaryCardsTest
- InventoryOfficerStockOutTest
- DispatchDeliveryController
- InventoryLedgerService
- StockInService
- WorkflowSmokeTestService
- InventoryOfficerStockInTest
- DispatchLiftingStatusController
- Illuminate\Support\Collection
- RoleAccessTestingTest
- SalesOfficerPaymentRecordingTest
- GarageTankService
- OperationalDataRepairService
- DatabaseIntegrityTest
- IntegrationTestingTest
- Illuminate\Http\RedirectResponse
- AIFeatureTestingTest
- AIServiceConfigurationTest
- CompleteWorkflowIntegrationTest
- FormValidationTest
- FunctionalTestingTest
- .__invoke
- Controller
- Illuminate\Database\Query\Builder
- TruckAvailabilityService
- AIDataPreparationServiceTest
- AnalyticsTestingTest
- BusinessInsightsTest
- Phase2GuardrailsAndIdempotencyTest
- PurchaseReceiptUploadTest
- DispatchLiftingStatusManagementTest
- DriverDeliveryController
- scripts
- app.js
- ConnectedLiftingWorkflowTest
- FrontendBugFixesTest
- InventoryLedgerTest
- InventoryVarianceExplanationsTest
- SalesOfficerCustomerManagementTest
- .index
- composer.json
- TurnoverReadinessTest
- HaulTruckAssignmentTest
- InventoryOfficerPurchaseManagementTest
- Phase4SecurityHardeningTest
- RevenueInsightsTest
- SalesOfficerReceivablesTrackingTest
- AdminSalesReportsTest
- PurchaseReceiptStatusTest
- SalesTrendSummariesTest
- AppServiceProvider.php
- AIServiceConfigurationTest.php
- require-dev
- setup
- PurchaseWorkflowService
- config
- SalesInitialPaymentTest
- .activeInventoryBalancesByFuelQuery
- AdminMonitoringTest
- FuelCatalogRegressionTest
- IdempotencyService
- InventoryCostService
- psr-4
- logging.php
- Not Ready Verdict
- MasterDataAndInventoryAccessTest
- require
- ExampleTest
- post-create-project-cmd
- extra
- keywords
- CJP Brand Logo
- admin.partials.lift-table
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
- `AIDataPreparationService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AIDataPreparationService.php → app/Services/DashboardSummaryService.php
- `SalesOfficerCustomerController` --references--> `SaleConfirmationService`  [EXTRACTED]
  app/Http/Controllers/SalesOfficerCustomerController.php → app/Services/SaleConfirmationService.php
- `AdminDashboardService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AdminDashboardService.php → app/Services/DashboardSummaryService.php
- `DispatchLiftingStatusController` --references--> `TruckAvailabilityService`  [EXTRACTED]
  app/Http/Controllers/DispatchLiftingStatusController.php → app/Services/TruckAvailabilityService.php
- `DriverLiftingStatusController` --references--> `TruckAvailabilityService`  [EXTRACTED]
  app/Http/Controllers/DriverLiftingStatusController.php → app/Services/TruckAvailabilityService.php

## Import Cycles
- None detected.

## Communities (160 total, 70 thin omitted)

### Community 0 - "Illuminate\Support\Facades\Schema"
Cohesion: 0.04
Nodes (8): nextLocationCode(), up(), backfillDirectDepotCosts(), backfillGarageCosts(), up(), Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 1 - "AIDataPreparationService"
Cohesion: 0.06
Nodes (6): AIDataPreparationService, AIService, BusinessInsightService, InventoryVarianceExplanationService, RevenueInsightService, SalesTrendSummaryService

### Community 2 - "DashboardSummaryService"
Cohesion: 0.08
Nodes (10): EnsureUserHasRole, SecurityHeaders, DashboardSummaryService, CarbonImmutable, Closure, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware (+2 more)

### Community 4 - "AdminMonitoringController"
Cohesion: 0.12
Nodes (5): AdminMonitoringController, PurchaseService, StockOutFinancialService, SaleCorrectionService, TruckAvailabilityService

### Community 5 - "Illuminate\Http\Request"
Cohesion: 0.14
Nodes (9): AdminDashboardController, InventoryOfficerLedgerController, ApprovedFuelType, Carbon\CarbonImmutable, Illuminate\Http\Request, Illuminate\Http\Response, Illuminate\Validation\Rule, Illuminate\Validation\Rules\Password (+1 more)

### Community 6 - "User"
Cohesion: 0.09
Nodes (6): User, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, AdminUserManagementTest, Phase5PerformanceTest

### Community 7 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.13
Nodes (12): Carbon\Carbon, Illuminate\Auth\Access\AuthorizationException, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, Illuminate\Http\Client\Response, Illuminate\Support\Facades\Config, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Http (+4 more)

### Community 9 - "TestCase.php"
Cohesion: 0.08
Nodes (7): Illuminate\Database\QueryException, Illuminate\Support\Facades\Blade, Illuminate\Support\Str, Pdo\Mysql, Phase3OperationalAcceptanceTest, StatusBadgeTest, TruckManagementTest

### Community 10 - ".rule"
Cohesion: 0.17
Nodes (3): AdminUserManagementController, User, Illuminate\Validation\Rules\Exists

### Community 12 - "User.php"
Cohesion: 0.12
Nodes (14): PasswordResetCodeMail, UserFactory, Illuminate\Bus\Queueable, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Mail\Mailable, Illuminate\Queue\SerializesModels, Illuminate\Support\Carbon, Illuminate\Support\Facades\Hash (+6 more)

### Community 13 - "PurchaseService"
Cohesion: 0.15
Nodes (4): IdempotencyService, PurchaseService, PurchaseWorkflowService, WorkflowAlertService

### Community 16 - "StockOutReleaseService"
Cohesion: 0.16
Nodes (5): SaleConfirmationService, Closure, IdempotencyService, InventoryCostService, StockOutReleaseService

### Community 17 - "devDependencies"
Cohesion: 0.09
Nodes (21): axios, chart.js, concurrently, laravel-vite-plugin, devDependencies, axios, chart.js, concurrently (+13 more)

### Community 22 - "StockInService"
Cohesion: 0.19
Nodes (4): IdempotencyService, PurchaseWorkflowService, WorkflowAlertService, StockInService

### Community 30 - "OperationalDataRepairService"
Cohesion: 0.23
Nodes (5): OperationalDataRepairService, DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder, SaleConfirmationService

### Community 39 - ".__invoke"
Cohesion: 0.21
Nodes (4): AdminBusinessInsightController, AdminRevenueInsightController, AdminSalesTrendSummaryController, Controller

### Community 40 - "Controller"
Cohesion: 0.21
Nodes (3): AdminInventoryVarianceExplanationController, HaulTruckAssignmentController, Controller

### Community 41 - "Illuminate\Database\Query\Builder"
Cohesion: 0.30
Nodes (3): StockOutFinancialService, Illuminate\Database\Query\Builder, Illuminate\Validation\ValidationException

### Community 47 - "PurchaseReceiptUploadTest"
Cohesion: 0.36
Nodes (3): Illuminate\Http\UploadedFile, UploadedFile, PurchaseReceiptUploadTest

### Community 50 - "scripts"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 51 - "app.js"
Cohesion: 0.21
Nodes (5): closeModal(), exportVisibleTable(), openModal(), sortVisibleTable(), visibleTableFor()

### Community 58 - "composer.json"
Cohesion: 0.18
Nodes (10): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+2 more)

### Community 59 - "TurnoverReadinessTest"
Cohesion: 0.20
Nodes (4): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Route, TurnoverReadinessTest

### Community 68 - "AppServiceProvider.php"
Cohesion: 0.29
Nodes (4): AppServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 69 - "AIServiceConfigurationTest.php"
Cohesion: 0.29
Nodes (5): Illuminate\Http\Client\ConnectionException, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\Log, RuntimeException, Throwable

### Community 70 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 71 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 73 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 80 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 81 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 82 - "Not Ready Verdict"
Cohesion: 0.40
Nodes (5): Live Operational Data Gap, Not Ready Verdict, CJP Southern Star OPC System and Audit Report, Final Turnover Gate, Turnover Readiness Checklist

### Community 84 - "require"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 86 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 87 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 88 - "keywords"
Cohesion: 0.67
Nodes (3): keywords, framework, laravel

### Community 90 - "CJP Brand Logo"
Cohesion: 0.67
Nodes (3): CJP Brand Logo, CJP Monogram, Green Star Symbol

## Knowledge Gaps
- **67 isolated node(s):** `private`, `$schema`, `build`, `dev`, `type` (+62 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **70 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Work-memory lessons

**Preferred sources** — corroborated by past sessions; start here.
- `SalesOfficerCustomerController` (8× useful, score=7.828401161)
- `StockOutReleaseService` (7× useful, score=6.775342219)
- `InventoryOfficerPurchaseController` (7× useful, score=6.426742948)
- `DispatchDeliveryController` (6× useful, score=5.619224816)
- `DriverDeliveryController` (5× useful, score=4.751487153)
- `modal.blade.php` (5× useful, score=4.732899588)
- `InventoryLedgerService` (5× useful, score=4.60511153)
- `SaleCorrectionService` (3× useful, score=2.996802834)
- `SalesOfficerSalesManagementTest` (3× useful, score=2.994327716)
- `FrontendBugFixesTest` (3× useful, score=2.941988754) _(code changed — re-verify)_

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User` to `AIDataPreparationService`, `Illuminate\Foundation\Testing\RefreshDatabase`, `RoleBasedAccessControlTest`, `TestCase.php`, `User.php`, `SalesOfficerSalesManagementTest`, `InventoryOfficerStockOutTest`, `InventoryOfficerStockInTest`, `DatabaseIntegrityTest`, `IntegrationTestingTest`, `CompleteWorkflowIntegrationTest`, `FormValidationTest`, `FunctionalTestingTest`, `Controller`, `AIDataPreparationServiceTest`, `AnalyticsTestingTest`, `BusinessInsightsTest`, `Phase2GuardrailsAndIdempotencyTest`, `PurchaseReceiptUploadTest`, `DispatchLiftingStatusManagementTest`, `ConnectedLiftingWorkflowTest`, `FrontendBugFixesTest`, `InventoryLedgerTest`, `InventoryVarianceExplanationsTest`, `HaulTruckAssignmentTest`, `RevenueInsightsTest`, `PurchaseReceiptStatusTest`, `SalesTrendSummariesTest`, `SalesInitialPaymentTest`, `AdminMonitoringTest`, `FuelCatalogRegressionTest`, `MasterDataAndInventoryAccessTest`?**
  _High betweenness centrality (0.106) - this node is a cross-community bridge._
- **Why does `DashboardSummaryService` connect `DashboardSummaryService` to `AIDataPreparationService`, `Illuminate\Support\Collection`, `Illuminate\Http\Request`, `Illuminate\Foundation\Testing\RefreshDatabase`?**
  _High betweenness centrality (0.075) - this node is a cross-community bridge._
- **Why does `AIDataPreparationService` connect `AIDataPreparationService` to `DashboardSummaryService`, `Illuminate\Foundation\Testing\RefreshDatabase`?**
  _High betweenness centrality (0.045) - this node is a cross-community bridge._
- **Are the 85 inferred relationships involving `User` (e.g. with `.records()` and `.baseRecords()`) actually correct?**
  _`User` has 85 INFERRED edges - model-reasoned connections that need verification._
- **What connects `private`, `$schema`, `build` to the rest of the system?**
  _67 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Illuminate\Support\Facades\Schema` be split into smaller, more focused modules?**
  _Cohesion score 0.041403508771929824 - nodes in this community are weakly interconnected._
- **Should `AIDataPreparationService` be split into smaller, more focused modules?**
  _Cohesion score 0.0554954954954955 - nodes in this community are weakly interconnected._