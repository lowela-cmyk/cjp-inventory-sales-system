# Graph Report - cjp_inventory_sales  (2026-09-18)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1533 nodes · 3617 edges · 140 communities (88 shown, 52 thin omitted)
- Extraction: 99% EXTRACTED · 1% INFERRED · 0% AMBIGUOUS · INFERRED: 43 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `f3da18d6`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- SalesOfficerCustomerController
- AIDataPreparationService
- DashboardSummaryService
- Illuminate\Support\Facades\Schema
- InventoryOfficerPurchaseController
- PurchaseService
- Illuminate\Foundation\Testing\RefreshDatabase
- OperationalDataRepairService
- AdminMonitoringController
- DispatchDeliveryController
- RoleBasedAccessControlTest
- Illuminate\Http\Request
- User
- AdminSalesReportController
- .rule
- SalesOfficerSalesManagementTest
- TestCase
- devDependencies
- PurchaseReceiptUploadTest
- DashboardSummaryCardsTest
- InventoryLedgerService
- WorkflowSmokeTestService
- InventoryOfficerStockInTest
- InventoryOfficerStockOutTest
- AuthController.php
- StockInService
- RoleAccessTestingTest
- SalesOfficerPaymentRecordingTest
- .__invoke
- DatabaseIntegrityTest
- IntegrationTestingTest
- AIFeatureTestingTest
- AIServiceConfigurationTest
- CompleteWorkflowIntegrationTest
- FormValidationTest
- FunctionalTestingTest
- scripts
- AIDataPreparationServiceTest
- AnalyticsTestingTest
- BusinessInsightsTest
- Phase2GuardrailsAndIdempotencyTest
- Illuminate\View\View
- GarageTankService.php
- DispatchLiftingStatusManagementTest
- app.js
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
- InventoryLedgerTest
- Controller
- FrontendBugFixesTest
- SalesTrendSummariesTest
- AppServiceProvider.php
- require-dev
- setup
- .update
- config
- ConnectedLiftingWorkflowTest
- UserFactory.php
- AdminMonitoringTest
- FuelCatalogRegressionTest
- Phase3OperationalAcceptanceTest
- psr-4
- logging.php
- Not Ready Verdict
- MasterDataAndInventoryAccessTest
- require
- post-create-project-cmd
- ExampleTest
- autoload-dev
- extra
- CJP Brand Logo
- admin.partials.lift-table
- database.php
- Current Stock-Out Fulfillment Model
- CarbonImmutable
- Closure
- Closure
- Robots Policy
- Laravel Framework

## God Nodes (most connected - your core abstractions)
1. `User` - 76 edges
2. `DashboardSummaryService` - 74 edges
3. `SalesOfficerCustomerController` - 70 edges
4. `InventoryOfficerPurchaseController` - 57 edges
5. `AdminMonitoringController` - 44 edges
6. `AIDataPreparationService` - 43 edges
7. `RoleBasedAccessControlTest` - 38 edges
8. `PurchaseService` - 33 edges
9. `SalesOfficerSalesManagementTest` - 27 edges
10. `AIService` - 26 edges

## Surprising Connections (you probably didn't know these)
- `SalesOfficerCustomerController` --references--> `IdempotencyService`  [EXTRACTED]
  app/Http/Controllers/SalesOfficerCustomerController.php → app/Services/IdempotencyService.php
- `SalesOfficerCustomerController` --references--> `SaleConfirmationService`  [EXTRACTED]
  app/Http/Controllers/SalesOfficerCustomerController.php → app/Services/SaleConfirmationService.php
- `AdminDashboardService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AdminDashboardService.php → app/Services/DashboardSummaryService.php
- `AIDataPreparationService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AIDataPreparationService.php → app/Services/DashboardSummaryService.php
- `AdminUserManagementTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/AdminUserManagementTest.php → tests/TestCase.php

## Import Cycles
- None detected.

## Communities (140 total, 52 thin omitted)

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
Nodes (5): nextLocationCode(), up(), Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 4 - "InventoryOfficerPurchaseController"
Cohesion: 0.08
Nodes (3): InventoryOfficerPurchaseController, Closure, Illuminate\Pagination\LengthAwarePaginator

### Community 5 - "PurchaseService"
Cohesion: 0.08
Nodes (5): DispatchLiftingStatusController, IdempotencyService, PurchaseService, PurchaseWorkflowService, WorkflowAlertService

### Community 6 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.16
Nodes (8): Carbon\Carbon, Illuminate\Database\QueryException, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Http\Client\Response, Illuminate\Support\Facades\Config, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Storage, Illuminate\Support\Str

### Community 7 - "OperationalDataRepairService"
Cohesion: 0.09
Nodes (10): IdempotencyService, Closure, OperationalDataRepairService, GarageTankService, SaleConfirmationService, Closure, StockOutReleaseService, DatabaseSeeder (+2 more)

### Community 9 - "DispatchDeliveryController"
Cohesion: 0.11
Nodes (3): DispatchDeliveryController, DriverDeliveryController, CarbonImmutable

### Community 11 - "Illuminate\Http\Request"
Cohesion: 0.14
Nodes (11): AdminInventoryVarianceExplanationController, ApprovedFuelType, Carbon\CarbonImmutable, Illuminate\Auth\Access\AuthorizationException, Illuminate\Database\Query\Builder, Illuminate\Http\RedirectResponse, Illuminate\Http\Request, Illuminate\Support\Facades\Validator (+3 more)

### Community 12 - "User"
Cohesion: 0.09
Nodes (6): User, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, AdminUserManagementTest, Phase5PerformanceTest

### Community 13 - "AdminSalesReportController"
Cohesion: 0.18
Nodes (3): AdminSalesReportController, Illuminate\Contracts\Database\Query\Builder, Illuminate\Http\Response

### Community 16 - "TestCase"
Cohesion: 0.12
Nodes (10): Illuminate\Foundation\Testing\TestCase, Illuminate\Http\Client\ConnectionException, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\Http, Illuminate\Support\Facades\Log, RuntimeException, DeliveryWorkflowRemovalTest, ExampleTest (+2 more)

### Community 17 - "devDependencies"
Cohesion: 0.09
Nodes (21): axios, chart.js, concurrently, laravel-vite-plugin, devDependencies, axios, chart.js, concurrently (+13 more)

### Community 18 - "PurchaseReceiptUploadTest"
Cohesion: 0.20
Nodes (5): Illuminate\Http\UploadedFile, UploadedFile, PurchaseReceiptStatusTest, UploadedFile, PurchaseReceiptUploadTest

### Community 24 - "AuthController.php"
Cohesion: 0.15
Nodes (10): PasswordResetCodeMail, Illuminate\Bus\Queueable, Illuminate\Mail\Mailable, Illuminate\Queue\SerializesModels, Illuminate\Support\Carbon, Illuminate\Support\Facades\Hash, Illuminate\Support\Facades\Mail, Illuminate\Validation\ValidationException (+2 more)

### Community 28 - ".__invoke"
Cohesion: 0.17
Nodes (5): AdminBusinessInsightController, AdminRevenueInsightController, AdminSalesTrendSummaryController, Controller, InventoryOfficerLedgerController

### Community 36 - "scripts"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 44 - "app.js"
Cohesion: 0.21
Nodes (5): closeModal(), exportVisibleTable(), openModal(), sortVisibleTable(), visibleTableFor()

### Community 47 - "composer.json"
Cohesion: 0.18
Nodes (10): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type (+2 more)

### Community 48 - "TurnoverReadinessTest"
Cohesion: 0.20
Nodes (4): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Route, TurnoverReadinessTest

### Community 56 - "Controller"
Cohesion: 0.33
Nodes (3): AdminDashboardController, DriverLiftingStatusController, Controller

### Community 59 - "AppServiceProvider.php"
Cohesion: 0.29
Nodes (4): AppServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 60 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 61 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 63 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 65 - "UserFactory.php"
Cohesion: 0.47
Nodes (3): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, static

### Community 69 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 70 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 71 - "Not Ready Verdict"
Cohesion: 0.40
Nodes (5): Live Operational Data Gap, Not Ready Verdict, CJP Southern Star OPC System and Audit Report, Final Turnover Gate, Turnover Readiness Checklist

### Community 73 - "require"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 74 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 76 - "autoload-dev"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 77 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 78 - "CJP Brand Logo"
Cohesion: 0.67
Nodes (3): CJP Brand Logo, CJP Monogram, Green Star Symbol

## Knowledge Gaps
- **66 isolated node(s):** `private`, `$schema`, `build`, `dev`, `type` (+61 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **52 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Work-memory lessons

**Preferred sources** — corroborated by past sessions; start here.
- `InventoryLedgerService` (3× useful, score=2.999043094)
- `InventoryOfficerPurchaseController` (3× useful, score=2.927148084)
- `modal.blade.php` (2× useful, score=1.999707168)
- `DispatchDeliveryController` (2× useful, score=1.999264433) _(code changed — re-verify)_
- `WorkflowSmokeTestService` (2× useful, score=1.927703889)

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `DashboardSummaryService` connect `DashboardSummaryService` to `SalesOfficerCustomerController`, `AIDataPreparationService`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\View\View`, `Illuminate\Http\Request`, `AuthController.php`?**
  _High betweenness centrality (0.111) - this node is a cross-community bridge._
- **Why does `User` connect `User` to `ConnectedLiftingWorkflowTest`, `UserFactory.php`, `AIDataPreparationService`, `AdminMonitoringTest`, `AIDataPreparationServiceTest`, `Illuminate\Foundation\Testing\RefreshDatabase`, `BusinessInsightsTest`, `Illuminate\Http\Request`, `TestCase`, `HaulTruckAssignmentTest`, `RevenueInsightsTest`, `InventoryLedgerTest`, `AuthController.php`, `FrontendBugFixesTest`, `SalesTrendSummariesTest`?**
  _High betweenness centrality (0.049) - this node is a cross-community bridge._
- **Why does `SalesOfficerCustomerController` connect `SalesOfficerCustomerController` to `Controller`, `Illuminate\View\View`, `Illuminate\Http\Request`, `OperationalDataRepairService`?**
  _High betweenness centrality (0.048) - this node is a cross-community bridge._
- **Are the 9 inferred relationships involving `User` (e.g. with `.records()` and `.baseRecords()`) actually correct?**
  _`User` has 9 INFERRED edges - model-reasoned connections that need verification._
- **What connects `private`, `$schema`, `build` to the rest of the system?**
  _66 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `SalesOfficerCustomerController` be split into smaller, more focused modules?**
  _Cohesion score 0.0558641975308642 - nodes in this community are weakly interconnected._
- **Should `AIDataPreparationService` be split into smaller, more focused modules?**
  _Cohesion score 0.0554954954954955 - nodes in this community are weakly interconnected._