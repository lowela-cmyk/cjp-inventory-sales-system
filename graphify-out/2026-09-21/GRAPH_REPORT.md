# Graph Report - cjp_inventory_sales  (2026-09-21)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1607 nodes · 3844 edges · 156 communities (91 shown, 65 thin omitted)
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
- TruckAvailabilityService
- StockOutReleaseService
- Illuminate\Http\Request
- AdminSalesReportController
- PurchaseService
- .rule
- SalesOfficerSalesManagementTest
- Illuminate\View\View
- devDependencies
- AdminUserManagementTest
- DashboardSummaryCardsTest
- InventoryOfficerStockOutTest
- DispatchDeliveryController
- InventoryLedgerService
- StockInService
- WorkflowSmokeTestService
- InventoryOfficerStockInTest
- TestCase
- RoleBasedAccessControlTest.php
- RoleAccessTestingTest
- SalesOfficerPaymentRecordingTest
- GarageTankService
- OperationalDataRepairService
- DatabaseIntegrityTest
- IntegrationTestingTest
- AIFeatureTestingTest
- AIServiceConfigurationTest
- CompleteWorkflowIntegrationTest
- FormValidationTest
- FunctionalTestingTest
- .__invoke
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
- HaulTruckAssignmentTest
- InventoryOfficerPurchaseManagementTest
- Phase4SecurityHardeningTest
- RevenueInsightsTest
- SalesOfficerReceivablesTrackingTest
- AdminSalesReportsTest
- FrontendBugFixesTest
- TruckController
- PurchaseReceiptStatusTest
- SalesTrendSummariesTest
- PasswordResetCodeMail
- AppServiceProvider.php
- require-dev
- setup
- Phase5PerformanceTest
- PurchaseWorkflowService
- config
- AdminInventoryVarianceExplanationController.php
- AdminMonitoringTest
- FuelCatalogRegressionTest
- Phase3OperationalAcceptanceTest
- psr-4
- logging.php
- Not Ready Verdict
- MasterDataAndInventoryAccessTest
- require
- post-create-project-cmd
- StatusBadgeTest.php
- ExampleTest
- autoload-dev
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
- `AdminDashboardService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AdminDashboardService.php → app/Services/DashboardSummaryService.php
- `SalesOfficerCustomerController` --references--> `IdempotencyService`  [EXTRACTED]
  app/Http/Controllers/SalesOfficerCustomerController.php → app/Services/IdempotencyService.php
- `SalesOfficerCustomerController` --references--> `SaleConfirmationService`  [EXTRACTED]
  app/Http/Controllers/SalesOfficerCustomerController.php → app/Services/SaleConfirmationService.php
- `AIDataPreparationService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AIDataPreparationService.php → app/Services/DashboardSummaryService.php
- `InventoryOfficerLedgerController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/InventoryOfficerLedgerController.php → app/Http/Controllers/Controller.php

## Import Cycles
- None detected.

## Communities (156 total, 65 thin omitted)

### Community 0 - "SalesOfficerCustomerController"
Cohesion: 0.06
Nodes (4): Closure, SalesOfficerCustomerController, AdminDashboardService, Illuminate\Support\Collection

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
Nodes (11): Carbon\Carbon, Illuminate\Auth\Access\AuthorizationException, Illuminate\Database\QueryException, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Http\Client\Response, Illuminate\Support\Facades\Config, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Storage (+3 more)

### Community 6 - "InventoryOfficerPurchaseController"
Cohesion: 0.08
Nodes (3): InventoryOfficerPurchaseController, Closure, Illuminate\Pagination\LengthAwarePaginator

### Community 7 - "User"
Cohesion: 0.07
Nodes (5): User, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, RoleBasedAccessControlTest

### Community 8 - "TruckAvailabilityService"
Cohesion: 0.11
Nodes (5): DispatchLiftingStatusController, DriverLiftingStatusController, HaulTruckAssignmentController, TruckAvailabilityService, Controller

### Community 9 - "StockOutReleaseService"
Cohesion: 0.12
Nodes (8): IdempotencyService, Closure, InventoryCostService, Closure, SaleConfirmationService, Closure, IdempotencyService, StockOutReleaseService

### Community 10 - "Illuminate\Http\Request"
Cohesion: 0.19
Nodes (4): AuthController, Illuminate\Http\RedirectResponse, Illuminate\Http\Request, Illuminate\Validation\Rules\Password

### Community 12 - "PurchaseService"
Cohesion: 0.15
Nodes (4): IdempotencyService, PurchaseService, PurchaseWorkflowService, WorkflowAlertService

### Community 15 - "Illuminate\View\View"
Cohesion: 0.19
Nodes (8): AdminDashboardController, InventoryOfficerLedgerController, ApprovedFuelType, Carbon\CarbonImmutable, Illuminate\Http\Response, Illuminate\Validation\Rule, Illuminate\Validation\Rules\Exists, Illuminate\View\View

### Community 16 - "devDependencies"
Cohesion: 0.09
Nodes (21): axios, chart.js, concurrently, laravel-vite-plugin, devDependencies, axios, chart.js, concurrently (+13 more)

### Community 22 - "StockInService"
Cohesion: 0.19
Nodes (4): IdempotencyService, PurchaseWorkflowService, WorkflowAlertService, StockInService

### Community 25 - "TestCase"
Cohesion: 0.15
Nodes (8): Illuminate\Foundation\Testing\TestCase, Illuminate\Http\Client\ConnectionException, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\Http, Illuminate\Support\Facades\Log, DeliveryWorkflowRemovalTest, ExampleTest, TestCase

### Community 26 - "RoleBasedAccessControlTest.php"
Cohesion: 0.12
Nodes (10): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Support\Carbon, Illuminate\Support\Facades\Hash, Illuminate\Support\Facades\Mail, PHPUnit\Framework\Attributes\DataProvider, RuntimeException, static (+2 more)

### Community 30 - "OperationalDataRepairService"
Cohesion: 0.23
Nodes (5): OperationalDataRepairService, DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder, SaleConfirmationService

### Community 38 - ".__invoke"
Cohesion: 0.21
Nodes (4): AdminBusinessInsightController, AdminRevenueInsightController, AdminSalesTrendSummaryController, Controller

### Community 39 - "scripts"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 45 - "PurchaseReceiptUploadTest"
Cohesion: 0.36
Nodes (3): Illuminate\Http\UploadedFile, UploadedFile, PurchaseReceiptUploadTest

### Community 47 - "app.js"
Cohesion: 0.21
Nodes (5): closeModal(), exportVisibleTable(), openModal(), sortVisibleTable(), visibleTableFor()

### Community 52 - "composer.json"
Cohesion: 0.18
Nodes (10): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type (+2 more)

### Community 53 - "TurnoverReadinessTest"
Cohesion: 0.20
Nodes (4): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Route, TurnoverReadinessTest

### Community 64 - "PasswordResetCodeMail"
Cohesion: 0.36
Nodes (5): PasswordResetCodeMail, Illuminate\Bus\Queueable, Illuminate\Mail\Mailable, Illuminate\Queue\SerializesModels, self

### Community 65 - "AppServiceProvider.php"
Cohesion: 0.29
Nodes (4): AppServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 66 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 67 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 70 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

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

### Community 83 - "autoload-dev"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 84 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 85 - "CJP Brand Logo"
Cohesion: 0.67
Nodes (3): CJP Brand Logo, CJP Monogram, Green Star Symbol

## Knowledge Gaps
- **67 isolated node(s):** `private`, `$schema`, `build`, `dev`, `type` (+62 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **65 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Work-memory lessons

**Preferred sources** — corroborated by past sessions; start here.
- `DispatchDeliveryController` (4× useful, score=3.841443247)
- `InventoryOfficerPurchaseController` (4× useful, score=3.698656913) _(code changed — re-verify)_
- `DriverDeliveryController` (3× useful, score=2.920290358)
- `InventoryLedgerService` (3× useful, score=2.765010109)
- `modal.blade.php` (2× useful, score=1.843658247)
- `WorkflowSmokeTestService` (2× useful, score=1.777273808)

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `DashboardSummaryService` connect `DashboardSummaryService` to `SalesOfficerCustomerController`, `AIDataPreparationService`, `Illuminate\Foundation\Testing\RefreshDatabase`, `AdminInventoryVarianceExplanationController.php`, `Illuminate\Http\Request`, `Illuminate\View\View`?**
  _High betweenness centrality (0.105) - this node is a cross-community bridge._
- **Why does `User` connect `User` to `AIDataPreparationService`, `Illuminate\Foundation\Testing\RefreshDatabase`, `SalesOfficerSalesManagementTest`, `AdminUserManagementTest`, `InventoryOfficerStockOutTest`, `InventoryOfficerStockInTest`, `TestCase`, `RoleBasedAccessControlTest.php`, `IntegrationTestingTest`, `AIDataPreparationServiceTest`, `AnalyticsTestingTest`, `BusinessInsightsTest`, `PurchaseReceiptUploadTest`, `DispatchLiftingStatusManagementTest`, `ConnectedLiftingWorkflowTest`, `InventoryLedgerTest`, `InventoryVarianceExplanationsTest`, `HaulTruckAssignmentTest`, `RevenueInsightsTest`, `FrontendBugFixesTest`, `PurchaseReceiptStatusTest`, `SalesTrendSummariesTest`, `Phase5PerformanceTest`, `AdminInventoryVarianceExplanationController.php`, `AdminMonitoringTest`, `FuelCatalogRegressionTest`, `MasterDataAndInventoryAccessTest`?**
  _High betweenness centrality (0.104) - this node is a cross-community bridge._
- **Why does `SalesOfficerCustomerController` connect `SalesOfficerCustomerController` to `TruckAvailabilityService`, `StockOutReleaseService`, `Illuminate\Http\Request`, `Illuminate\View\View`?**
  _High betweenness centrality (0.041) - this node is a cross-community bridge._
- **Are the 71 inferred relationships involving `User` (e.g. with `.records()` and `.records()`) actually correct?**
  _`User` has 71 INFERRED edges - model-reasoned connections that need verification._
- **What connects `private`, `$schema`, `build` to the rest of the system?**
  _67 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `SalesOfficerCustomerController` be split into smaller, more focused modules?**
  _Cohesion score 0.0558641975308642 - nodes in this community are weakly interconnected._
- **Should `AIDataPreparationService` be split into smaller, more focused modules?**
  _Cohesion score 0.0554954954954955 - nodes in this community are weakly interconnected._