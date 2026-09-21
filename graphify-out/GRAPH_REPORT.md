# Graph Report - cjp_inventory_sales  (2026-09-21)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1608 nodes · 3845 edges · 155 communities (93 shown, 62 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 103 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `9975cbe2`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- SalesOfficerCustomerController
- AIDataPreparationService
- DashboardSummaryService
- Illuminate\Support\Facades\Schema
- AdminMonitoringController
- Illuminate\Foundation\Testing\RefreshDatabase
- InventoryOfficerPurchaseController
- User
- RoleBasedAccessControlTest
- TruckAvailabilityService
- AdminSalesReportController
- PurchaseService
- Illuminate\View\View
- .rule
- SalesOfficerSalesManagementTest
- Illuminate\Http\Request
- StockOutReleaseService
- devDependencies
- AuthController.php
- DashboardSummaryCardsTest
- InventoryOfficerStockOutTest
- DispatchDeliveryController
- InventoryLedgerService
- StockInService
- WorkflowSmokeTestService
- InventoryOfficerStockInTest
- RoleAccessTestingTest
- SalesOfficerPaymentRecordingTest
- GarageTankService
- OperationalDataRepairService
- DatabaseIntegrityTest
- IntegrationTestingTest
- TestCase
- AIFeatureTestingTest
- AIServiceConfigurationTest
- CompleteWorkflowIntegrationTest
- FormValidationTest
- FunctionalTestingTest
- .__invoke
- Controller
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
- TruckController
- AppServiceProvider.php
- require-dev
- setup
- PurchaseWorkflowService
- config
- UserFactory.php
- AdminMonitoringTest
- FuelCatalogRegressionTest
- Phase3OperationalAcceptanceTest
- AIServiceConfigurationTest.php
- psr-4
- logging.php
- Not Ready Verdict
- MasterDataAndInventoryAccessTest
- require
- post-create-project-cmd
- StatusBadgeTest.php
- ExampleTest
- extra
- autoload-dev
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
- UploadedFile

## God Nodes (most connected - your core abstractions)
1. `User` - 134 edges
2. `DashboardSummaryService` - 71 edges
3. `SalesOfficerCustomerController` - 70 edges
4. `InventoryOfficerPurchaseController` - 60 edges
5. `AdminMonitoringController` - 47 edges
6. `AIDataPreparationService` - 43 edges
7. `RoleBasedAccessControlTest` - 38 edges
8. `PurchaseService` - 27 edges
9. `SalesOfficerSalesManagementTest` - 27 edges
10. `AIService` - 26 edges

## Surprising Connections (you probably didn't know these)
- `SalesOfficerCustomerController` --references--> `SaleConfirmationService`  [EXTRACTED]
  app/Http/Controllers/SalesOfficerCustomerController.php → app/Services/SaleConfirmationService.php
- `AdminDashboardService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AdminDashboardService.php → app/Services/DashboardSummaryService.php
- `AIDataPreparationService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AIDataPreparationService.php → app/Services/DashboardSummaryService.php
- `InventoryOfficerLedgerController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/InventoryOfficerLedgerController.php → app/Http/Controllers/Controller.php
- `InventoryOfficerPurchaseController` --references--> `InventoryCostService`  [EXTRACTED]
  app/Http/Controllers/InventoryOfficerPurchaseController.php → app/Services/InventoryCostService.php

## Import Cycles
- None detected.

## Communities (155 total, 62 thin omitted)

### Community 0 - "SalesOfficerCustomerController"
Cohesion: 0.05
Nodes (6): Closure, SalesOfficerCustomerController, AdminDashboardService, IdempotencyService, Closure, Illuminate\Support\Collection

### Community 1 - "AIDataPreparationService"
Cohesion: 0.06
Nodes (6): AIDataPreparationService, AIService, BusinessInsightService, InventoryVarianceExplanationService, RevenueInsightService, SalesTrendSummaryService

### Community 2 - "DashboardSummaryService"
Cohesion: 0.08
Nodes (10): EnsureUserHasRole, SecurityHeaders, DashboardSummaryService, CarbonImmutable, Closure, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware (+2 more)

### Community 3 - "Illuminate\Support\Facades\Schema"
Cohesion: 0.05
Nodes (8): nextLocationCode(), up(), backfillDirectDepotCosts(), backfillGarageCosts(), up(), Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 4 - "AdminMonitoringController"
Cohesion: 0.10
Nodes (6): AdminMonitoringController, PurchaseService, StockOutFinancialService, Illuminate\Database\Query\Builder, Illuminate\Validation\ValidationException, TruckAvailabilityService

### Community 5 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.13
Nodes (11): Carbon\Carbon, Illuminate\Database\QueryException, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Http\Client\Response, Illuminate\Support\Facades\Config, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Http, Illuminate\Support\Facades\Storage (+3 more)

### Community 6 - "InventoryOfficerPurchaseController"
Cohesion: 0.08
Nodes (3): InventoryOfficerPurchaseController, Closure, Illuminate\Pagination\LengthAwarePaginator

### Community 7 - "User"
Cohesion: 0.09
Nodes (6): User, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, AdminUserManagementTest, Phase5PerformanceTest

### Community 9 - "TruckAvailabilityService"
Cohesion: 0.13
Nodes (3): DispatchLiftingStatusController, DriverLiftingStatusController, TruckAvailabilityService

### Community 11 - "PurchaseService"
Cohesion: 0.15
Nodes (4): IdempotencyService, PurchaseService, PurchaseWorkflowService, WorkflowAlertService

### Community 12 - "Illuminate\View\View"
Cohesion: 0.18
Nodes (7): InventoryOfficerLedgerController, ApprovedFuelType, Carbon\CarbonImmutable, Illuminate\Http\Response, Illuminate\Validation\Rule, Illuminate\Validation\Rules\Exists, Illuminate\View\View

### Community 15 - "Illuminate\Http\Request"
Cohesion: 0.23
Nodes (3): AuthController, Illuminate\Http\RedirectResponse, Illuminate\Http\Request

### Community 16 - "StockOutReleaseService"
Cohesion: 0.15
Nodes (6): InventoryCostService, Closure, SaleConfirmationService, Closure, IdempotencyService, StockOutReleaseService

### Community 17 - "devDependencies"
Cohesion: 0.09
Nodes (21): axios, chart.js, concurrently, laravel-vite-plugin, devDependencies, axios, chart.js, concurrently (+13 more)

### Community 18 - "AuthController.php"
Cohesion: 0.13
Nodes (13): PasswordResetCodeMail, Illuminate\Bus\Queueable, Illuminate\Mail\Mailable, Illuminate\Queue\SerializesModels, Illuminate\Support\Carbon, Illuminate\Support\Facades\Hash, Illuminate\Support\Facades\Mail, Illuminate\Validation\Rules\Password (+5 more)

### Community 23 - "StockInService"
Cohesion: 0.19
Nodes (4): IdempotencyService, PurchaseWorkflowService, WorkflowAlertService, StockInService

### Community 29 - "OperationalDataRepairService"
Cohesion: 0.23
Nodes (5): OperationalDataRepairService, DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder, SaleConfirmationService

### Community 32 - "TestCase"
Cohesion: 0.15
Nodes (6): Illuminate\Auth\Access\AuthorizationException, Illuminate\Foundation\Testing\TestCase, Illuminate\Support\Facades\Validator, DeliveryWorkflowRemovalTest, ExampleTest, TestCase

### Community 38 - ".__invoke"
Cohesion: 0.21
Nodes (4): AdminBusinessInsightController, AdminRevenueInsightController, AdminSalesTrendSummaryController, Controller

### Community 39 - "Controller"
Cohesion: 0.19
Nodes (4): AdminDashboardController, AdminInventoryVarianceExplanationController, HaulTruckAssignmentController, Controller

### Community 40 - "scripts"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 46 - "PurchaseReceiptUploadTest"
Cohesion: 0.36
Nodes (3): Illuminate\Http\UploadedFile, UploadedFile, PurchaseReceiptUploadTest

### Community 48 - "app.js"
Cohesion: 0.21
Nodes (5): closeModal(), exportVisibleTable(), openModal(), sortVisibleTable(), visibleTableFor()

### Community 53 - "composer.json"
Cohesion: 0.18
Nodes (10): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type (+2 more)

### Community 54 - "TurnoverReadinessTest"
Cohesion: 0.20
Nodes (4): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Route, TurnoverReadinessTest

### Community 65 - "AppServiceProvider.php"
Cohesion: 0.29
Nodes (4): AppServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 66 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 67 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 69 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 70 - "UserFactory.php"
Cohesion: 0.47
Nodes (3): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, static

### Community 74 - "AIServiceConfigurationTest.php"
Cohesion: 0.50
Nodes (3): Illuminate\Http\Client\ConnectionException, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\Log

### Community 75 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 76 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 77 - "Not Ready Verdict"
Cohesion: 0.40
Nodes (5): Live Operational Data Gap, Not Ready Verdict, CJP Southern Star OPC System and Audit Report, Final Turnover Gate, Turnover Readiness Checklist

### Community 79 - "require"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 80 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 83 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 84 - "autoload-dev"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 85 - "CJP Brand Logo"
Cohesion: 0.67
Nodes (3): CJP Brand Logo, CJP Monogram, Green Star Symbol

## Knowledge Gaps
- **67 isolated node(s):** `private`, `$schema`, `build`, `dev`, `type` (+62 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **62 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Work-memory lessons

**Preferred sources** — corroborated by past sessions; start here.
- `InventoryOfficerPurchaseController` (5× useful, score=4.697495602)
- `DispatchDeliveryController` (4× useful, score=3.840330367)
- `DriverDeliveryController` (3× useful, score=2.91944434)
- `InventoryLedgerService` (3× useful, score=2.764209076)
- `AdminMonitoringController` (2× useful, score=1.999551943)
- `DashboardSummaryService` (2× useful, score=1.888046924)
- `StockOutReleaseService` (2× useful, score=1.888046924)
- `modal.blade.php` (2× useful, score=1.843124133)
- `WorkflowSmokeTestService` (2× useful, score=1.776758926)

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User` to `AIDataPreparationService`, `Illuminate\Foundation\Testing\RefreshDatabase`, `RoleBasedAccessControlTest`, `SalesOfficerSalesManagementTest`, `AuthController.php`, `InventoryOfficerStockOutTest`, `InventoryOfficerStockInTest`, `IntegrationTestingTest`, `TestCase`, `Controller`, `AIDataPreparationServiceTest`, `AnalyticsTestingTest`, `BusinessInsightsTest`, `PurchaseReceiptUploadTest`, `DispatchLiftingStatusManagementTest`, `ConnectedLiftingWorkflowTest`, `InventoryLedgerTest`, `InventoryVarianceExplanationsTest`, `FrontendBugFixesTest`, `HaulTruckAssignmentTest`, `RevenueInsightsTest`, `PurchaseReceiptStatusTest`, `SalesTrendSummariesTest`, `UserFactory.php`, `AdminMonitoringTest`, `FuelCatalogRegressionTest`, `MasterDataAndInventoryAccessTest`?**
  _High betweenness centrality (0.121) - this node is a cross-community bridge._
- **Why does `DashboardSummaryService` connect `DashboardSummaryService` to `SalesOfficerCustomerController`, `AIDataPreparationService`, `TestCase`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\View\View`?**
  _High betweenness centrality (0.090) - this node is a cross-community bridge._
- **Why does `AIDataPreparationService` connect `AIDataPreparationService` to `TestCase`, `DashboardSummaryService`, `Illuminate\Foundation\Testing\RefreshDatabase`?**
  _High betweenness centrality (0.046) - this node is a cross-community bridge._
- **Are the 71 inferred relationships involving `User` (e.g. with `.records()` and `.records()`) actually correct?**
  _`User` has 71 INFERRED edges - model-reasoned connections that need verification._
- **What connects `private`, `$schema`, `build` to the rest of the system?**
  _67 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `SalesOfficerCustomerController` be split into smaller, more focused modules?**
  _Cohesion score 0.05078855920876771 - nodes in this community are weakly interconnected._
- **Should `AIDataPreparationService` be split into smaller, more focused modules?**
  _Cohesion score 0.0554954954954955 - nodes in this community are weakly interconnected._