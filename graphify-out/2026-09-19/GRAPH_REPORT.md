# Graph Report - cjp_inventory_sales  (2026-09-19)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1550 nodes · 3650 edges · 149 communities (88 shown, 61 thin omitted)
- Extraction: 99% EXTRACTED · 1% INFERRED · 0% AMBIGUOUS · INFERRED: 48 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `96effaeb`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- AIDataPreparationService
- DashboardSummaryService
- SalesOfficerCustomerController
- Illuminate\Support\Facades\Schema
- DispatchDeliveryController
- InventoryOfficerPurchaseController
- Illuminate\Foundation\Testing\RefreshDatabase
- OperationalDataRepairService
- AdminMonitoringController
- RoleBasedAccessControlTest
- User
- Illuminate\Http\Request
- web.php
- PurchaseService
- AdminSalesReportController
- AdminUserManagementController
- SalesOfficerSalesManagementTest
- devDependencies
- PurchaseReceiptUploadTest
- DashboardSummaryCardsTest
- InventoryLedgerService
- StockInService
- TestCase
- WorkflowSmokeTestService
- InventoryOfficerStockInTest
- InventoryOfficerStockOutTest
- RoleAccessTestingTest
- SalesOfficerPaymentRecordingTest
- AIServiceConfigurationTest.php
- DatabaseIntegrityTest
- IntegrationTestingTest
- PurchaseWorkflowService
- AIFeatureTestingTest
- AIServiceConfigurationTest
- CompleteWorkflowIntegrationTest
- FormValidationTest
- FunctionalTestingTest
- AdminBusinessInsightController.php
- scripts
- AIDataPreparationServiceTest
- AnalyticsTestingTest
- BusinessInsightsTest
- Phase2GuardrailsAndIdempotencyTest
- GarageTankService.php
- DispatchLiftingStatusManagementTest
- .update
- InventoryOfficerPurchaseController.php
- app.js
- InventoryLedgerTest
- InventoryVarianceExplanationsTest
- SalesOfficerCustomerManagementTest
- RoleBasedAccessControlTest.php
- composer.json
- TurnoverReadinessTest
- ConnectedLiftingWorkflowTest
- HaulTruckAssignmentTest
- InventoryOfficerPurchaseManagementTest
- Phase4SecurityHardeningTest
- RevenueInsightsTest
- SalesOfficerReceivablesTrackingTest
- AdminSalesReportsTest
- FrontendBugFixesTest
- SalesTrendSummariesTest
- AppServiceProvider.php
- require-dev
- setup
- config
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
- extra
- keywords
- CJP Brand Logo
- admin.partials.lift-table
- database.php
- Current Stock-Out Fulfillment Model
- CarbonImmutable
- CarbonImmutable
- Closure
- Closure
- Robots Policy
- PurchaseWorkflowService
- Laravel Framework
- WorkflowAlertService
- DispatchLiftingStatusController
- AuthController
- AdminRevenueInsightController.php
- AdminSalesTrendSummaryController.php

## God Nodes (most connected - your core abstractions)
1. `User` - 81 edges
2. `DashboardSummaryService` - 75 edges
3. `SalesOfficerCustomerController` - 70 edges
4. `InventoryOfficerPurchaseController` - 58 edges
5. `AdminMonitoringController` - 44 edges
6. `AIDataPreparationService` - 43 edges
7. `RoleBasedAccessControlTest` - 38 edges
8. `PurchaseService` - 33 edges
9. `SalesOfficerSalesManagementTest` - 27 edges
10. `AIService` - 26 edges

## Surprising Connections (you probably didn't know these)
- `AIDataPreparationService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AIDataPreparationService.php → app/Services/DashboardSummaryService.php
- `AdminDashboardService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AdminDashboardService.php → app/Services/DashboardSummaryService.php
- `AdminUserManagementTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/AdminUserManagementTest.php → tests/TestCase.php
- `AdminMonitoringController` --references--> `PurchaseService`  [EXTRACTED]
  app/Http/Controllers/AdminMonitoringController.php → app/Services/PurchaseService.php
- `InventoryOfficerPurchaseController` --references--> `PurchaseService`  [EXTRACTED]
  app/Http/Controllers/InventoryOfficerPurchaseController.php → app/Services/PurchaseService.php

## Import Cycles
- None detected.

## Communities (149 total, 61 thin omitted)

### Community 0 - "AIDataPreparationService"
Cohesion: 0.06
Nodes (6): AIDataPreparationService, AIService, BusinessInsightService, InventoryVarianceExplanationService, RevenueInsightService, SalesTrendSummaryService

### Community 1 - "DashboardSummaryService"
Cohesion: 0.06
Nodes (14): DriverDeliveryController, EnsureUserHasRole, SecurityHeaders, DashboardSummaryService, Carbon\CarbonImmutable, CarbonImmutable, Closure, Illuminate\Database\Query\Builder (+6 more)

### Community 2 - "SalesOfficerCustomerController"
Cohesion: 0.06
Nodes (4): Closure, SalesOfficerCustomerController, AdminDashboardService, Illuminate\Support\Collection

### Community 3 - "Illuminate\Support\Facades\Schema"
Cohesion: 0.05
Nodes (5): nextLocationCode(), up(), Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 5 - "InventoryOfficerPurchaseController"
Cohesion: 0.08
Nodes (3): InventoryOfficerPurchaseController, Closure, Illuminate\Pagination\LengthAwarePaginator

### Community 6 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.17
Nodes (8): Carbon\Carbon, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Http\Client\Response, Illuminate\Support\Facades\Config, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Http, Illuminate\Support\Facades\Storage, Illuminate\Support\Str

### Community 7 - "OperationalDataRepairService"
Cohesion: 0.09
Nodes (10): IdempotencyService, Closure, OperationalDataRepairService, GarageTankService, SaleConfirmationService, Closure, StockOutReleaseService, DatabaseSeeder (+2 more)

### Community 10 - "User"
Cohesion: 0.09
Nodes (6): User, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, AdminUserManagementTest, Phase5PerformanceTest

### Community 12 - "web.php"
Cohesion: 0.16
Nodes (7): AdminDashboardController, AdminInventoryVarianceExplanationController, ApprovedFuelType, Controller, Illuminate\Http\Response, Illuminate\Validation\Rule, Illuminate\Validation\Rules\Password

### Community 13 - "PurchaseService"
Cohesion: 0.15
Nodes (4): IdempotencyService, PurchaseWorkflowService, WorkflowAlertService, PurchaseService

### Community 17 - "devDependencies"
Cohesion: 0.09
Nodes (21): axios, chart.js, concurrently, laravel-vite-plugin, devDependencies, axios, chart.js, concurrently (+13 more)

### Community 18 - "PurchaseReceiptUploadTest"
Cohesion: 0.20
Nodes (5): Illuminate\Http\UploadedFile, UploadedFile, PurchaseReceiptStatusTest, UploadedFile, PurchaseReceiptUploadTest

### Community 21 - "StockInService"
Cohesion: 0.19
Nodes (4): IdempotencyService, PurchaseWorkflowService, WorkflowAlertService, StockInService

### Community 22 - "TestCase"
Cohesion: 0.22
Nodes (4): Illuminate\Foundation\Testing\TestCase, DeliveryWorkflowRemovalTest, ExampleTest, TestCase

### Community 28 - "AIServiceConfigurationTest.php"
Cohesion: 0.14
Nodes (9): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Http\Client\ConnectionException, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\Hash, Illuminate\Support\Facades\Log, RuntimeException, static (+1 more)

### Community 31 - "PurchaseWorkflowService"
Cohesion: 0.18
Nodes (3): DriverLiftingStatusController, PurchaseWorkflowService, WorkflowAlertService

### Community 37 - "AdminBusinessInsightController.php"
Cohesion: 0.24
Nodes (3): AdminBusinessInsightController, Controller, InventoryOfficerLedgerController

### Community 38 - "scripts"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 46 - "InventoryOfficerPurchaseController.php"
Cohesion: 0.22
Nodes (4): Illuminate\Auth\Access\AuthorizationException, Illuminate\Database\QueryException, Illuminate\Support\Facades\Validator, Illuminate\Validation\ValidationException

### Community 47 - "app.js"
Cohesion: 0.21
Nodes (5): closeModal(), exportVisibleTable(), openModal(), sortVisibleTable(), visibleTableFor()

### Community 51 - "RoleBasedAccessControlTest.php"
Cohesion: 0.24
Nodes (8): PasswordResetCodeMail, Illuminate\Bus\Queueable, Illuminate\Mail\Mailable, Illuminate\Queue\SerializesModels, Illuminate\Support\Carbon, Illuminate\Support\Facades\Mail, PHPUnit\Framework\Attributes\DataProvider, Symfony\Component\Mailer\Exception\TransportException

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

### Community 66 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 70 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 71 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 72 - "Not Ready Verdict"
Cohesion: 0.40
Nodes (5): Live Operational Data Gap, Not Ready Verdict, CJP Southern Star OPC System and Audit Report, Final Turnover Gate, Turnover Readiness Checklist

### Community 74 - "require"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 75 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 78 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 79 - "keywords"
Cohesion: 0.67
Nodes (3): keywords, framework, laravel

### Community 80 - "CJP Brand Logo"
Cohesion: 0.67
Nodes (3): CJP Brand Logo, CJP Monogram, Green Star Symbol

## Knowledge Gaps
- **66 isolated node(s):** `private`, `$schema`, `build`, `dev`, `type` (+61 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **61 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Work-memory lessons

**Preferred sources** — corroborated by past sessions; start here.
- `InventoryLedgerService` (3× useful, score=2.999043094) _(code changed — re-verify)_
- `InventoryOfficerPurchaseController` (3× useful, score=2.927148084) _(code changed — re-verify)_
- `modal.blade.php` (2× useful, score=1.999707168)
- `DispatchDeliveryController` (2× useful, score=1.999264433) _(code changed — re-verify)_
- `WorkflowSmokeTestService` (2× useful, score=1.927703889)

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `DashboardSummaryService` connect `DashboardSummaryService` to `AIDataPreparationService`, `SalesOfficerCustomerController`, `InventoryOfficerPurchaseController`, `Illuminate\Foundation\Testing\RefreshDatabase`, `web.php`, `InventoryOfficerPurchaseController.php`?**
  _High betweenness centrality (0.122) - this node is a cross-community bridge._
- **Why does `SalesOfficerCustomerController` connect `SalesOfficerCustomerController` to `web.php`, `OperationalDataRepairService`?**
  _High betweenness centrality (0.060) - this node is a cross-community bridge._
- **Why does `User` connect `User` to `AIDataPreparationService`, `AdminMonitoringTest`, `Illuminate\Foundation\Testing\RefreshDatabase`, `AIDataPreparationServiceTest`, `BusinessInsightsTest`, `web.php`, `InventoryOfficerPurchaseController.php`, `InventoryLedgerTest`, `HaulTruckAssignmentTest`, `TestCase`, `ConnectedLiftingWorkflowTest`, `InventoryOfficerStockInTest`, `RevenueInsightsTest`, `AIServiceConfigurationTest.php`, `FrontendBugFixesTest`, `SalesTrendSummariesTest`?**
  _High betweenness centrality (0.048) - this node is a cross-community bridge._
- **Are the 14 inferred relationships involving `User` (e.g. with `.records()` and `.baseRecords()`) actually correct?**
  _`User` has 14 INFERRED edges - model-reasoned connections that need verification._
- **What connects `private`, `$schema`, `build` to the rest of the system?**
  _66 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `AIDataPreparationService` be split into smaller, more focused modules?**
  _Cohesion score 0.0554954954954955 - nodes in this community are weakly interconnected._
- **Should `DashboardSummaryService` be split into smaller, more focused modules?**
  _Cohesion score 0.05873340143003064 - nodes in this community are weakly interconnected._