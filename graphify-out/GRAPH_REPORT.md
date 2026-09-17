# Graph Report - cjp_inventory_sales  (2026-09-16)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1352 nodes · 3288 edges · 146 communities (94 shown, 52 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS · INFERRED: 4 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `96d467c7`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- AIDataPreparationService
- DashboardSummaryService
- InventoryOfficerPurchaseController
- AdminMonitoringController
- SalesOfficerCustomerController
- RoleBasedAccessControlTest
- Illuminate\Support\Facades\DB
- Controller
- User
- TestCase
- AdminSalesReportController
- DispatchDeliveryController
- devDependencies
- Illuminate\Support\Collection
- Illuminate\Http\Request
- PurchaseReceiptUploadTest
- DashboardSummaryCardsTest
- AdminUserManagementController
- InventoryLedgerService
- WorkflowSmokeTestService
- InventoryOfficerStockInTest
- InventoryOfficerStockOutTest
- RoleAccessTestingTest
- SalesOfficerPaymentRecordingTest
- SalesOfficerSalesManagementTest
- DatabaseIntegrityTest
- IntegrationTestingTest
- AuthController.php
- AIFeatureTestingTest
- AIServiceConfigurationTest
- CompleteWorkflowIntegrationTest
- FormValidationTest
- FunctionalTestingTest
- Illuminate\Support\Str
- scripts
- AIDataPreparationServiceTest
- AnalyticsTestingTest
- BusinessInsightsTest
- DriverDeliveryController
- TurnoverReadinessTest
- DispatchLiftingStatusManagementTest
- InventoryVarianceExplanationsTest
- SalesOfficerCustomerManagementTest
- StockOutReleaseService
- composer.json
- HaulTruckAssignmentTest
- InventoryOfficerPurchaseManagementTest
- RevenueInsightsTest
- SalesOfficerReceivablesTrackingTest
- PasswordResetCodeMail
- Illuminate\Database\Migrations\Migration
- app.js
- AdminSalesReportsTest
- InventoryLedgerTest
- DispatchLiftingStatusController
- UserFactory.php
- FrontendBugFixesTest
- SalesTrendSummariesTest
- AppServiceProvider.php
- require-dev
- setup
- config
- Illuminate\Database\Schema\Blueprint
- Illuminate\Support\Facades\Schema
- AdminMonitoringTest
- AIServiceConfigurationTest.php
- psr-4
- logging.php
- DatabaseSeeder.php
- Not Ready Verdict
- MasterDataAndInventoryAccessTest
- .fuelTypeOptions
- require
- post-create-project-cmd
- 2026_09_06_000001_add_withdrawal_receipts_and_garage_tanks.php
- ExampleTest
- extra
- autoload-dev
- CJP Brand Logo
- ExampleTest
- admin.partials.lift-table
- Current Stock-Out Fulfillment Model
- Robots Policy
- Laravel Framework

## God Nodes (most connected - your core abstractions)
1. `User` - 203 edges
2. `DashboardSummaryService` - 75 edges
3. `TestCase` - 74 edges
4. `SalesOfficerCustomerController` - 68 edges
5. `InventoryOfficerPurchaseController` - 58 edges
6. `AIDataPreparationService` - 44 edges
7. `AdminMonitoringController` - 40 edges
8. `RoleBasedAccessControlTest` - 38 edges
9. `AIService` - 26 edges
10. `AdminSalesReportController` - 25 edges

## Surprising Connections (you probably didn't know these)
- `AdminInventoryVarianceExplanationController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/AdminInventoryVarianceExplanationController.php → app/Http/Controllers/Controller.php
- `AIDataPreparationService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AIDataPreparationService.php → app/Services/DashboardSummaryService.php
- `AdminDashboardService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AdminDashboardService.php → app/Services/DashboardSummaryService.php
- `AdminSalesReportController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/AdminSalesReportController.php → app/Http/Controllers/Controller.php
- `DispatchDeliveryController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/DispatchDeliveryController.php → app/Http/Controllers/Controller.php

## Import Cycles
- None detected.

## Communities (146 total, 52 thin omitted)

### Community 0 - "AIDataPreparationService"
Cohesion: 0.05
Nodes (7): AdminInventoryVarianceExplanationController, AIDataPreparationService, AIService, BusinessInsightService, InventoryVarianceExplanationService, RevenueInsightService, SalesTrendSummaryService

### Community 1 - "DashboardSummaryService"
Cohesion: 0.08
Nodes (9): EnsureUserHasRole, DashboardSummaryService, CarbonImmutable, Closure, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware, Illuminate\Support\Facades\Auth (+1 more)

### Community 6 - "Illuminate\Support\Facades\DB"
Cohesion: 0.16
Nodes (8): Carbon\CarbonImmutable, Illuminate\Database\Query\Builder, Illuminate\Http\Response, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Storage, Illuminate\Support\Facades\Validator, Illuminate\Validation\Rule, Illuminate\Validation\Rules\Password

### Community 7 - "Controller"
Cohesion: 0.12
Nodes (7): AdminBusinessInsightController, AdminRevenueInsightController, Controller, DriverLiftingStatusController, HaulTruckAssignmentController, CarbonImmutable, InventoryOfficerLedgerController

### Community 8 - "User"
Cohesion: 0.13
Nodes (3): User, Illuminate\Foundation\Auth\User, AdminUserManagementTest

### Community 9 - "TestCase"
Cohesion: 0.19
Nodes (6): Carbon\Carbon, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, Illuminate\Support\Facades\Config, Illuminate\Support\Facades\Http, TestCase

### Community 12 - "devDependencies"
Cohesion: 0.09
Nodes (21): axios, chart.js, concurrently, laravel-vite-plugin, devDependencies, axios, chart.js, concurrently (+13 more)

### Community 13 - "Illuminate\Support\Collection"
Cohesion: 0.15
Nodes (3): AdminDashboardController, AdminDashboardService, Illuminate\Support\Collection

### Community 14 - "Illuminate\Http\Request"
Cohesion: 0.22
Nodes (4): AdminSalesTrendSummaryController, AuthController, Illuminate\Http\RedirectResponse, Illuminate\Http\Request

### Community 15 - "PurchaseReceiptUploadTest"
Cohesion: 0.20
Nodes (5): Illuminate\Http\UploadedFile, UploadedFile, PurchaseReceiptStatusTest, UploadedFile, PurchaseReceiptUploadTest

### Community 28 - "AuthController.php"
Cohesion: 0.16
Nodes (9): Illuminate\Auth\Access\AuthorizationException, Illuminate\Support\Carbon, Illuminate\Support\Facades\Hash, Illuminate\Support\Facades\Mail, Illuminate\Validation\ValidationException, PHPUnit\Framework\Attributes\DataProvider, RuntimeException, Symfony\Component\Mailer\Exception\TransportException (+1 more)

### Community 34 - "Illuminate\Support\Str"
Cohesion: 0.14
Nodes (3): Illuminate\Database\QueryException, Illuminate\Support\Str, Pdo\Mysql

### Community 35 - "scripts"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 40 - "TurnoverReadinessTest"
Cohesion: 0.17
Nodes (5): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Route, DeliveryWorkflowRemovalTest, TurnoverReadinessTest

### Community 45 - "composer.json"
Cohesion: 0.18
Nodes (10): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type (+2 more)

### Community 50 - "PasswordResetCodeMail"
Cohesion: 0.27
Nodes (5): PasswordResetCodeMail, Illuminate\Bus\Queueable, Illuminate\Mail\Mailable, Illuminate\Queue\SerializesModels, self

### Community 52 - "app.js"
Cohesion: 0.27
Nodes (5): closeModal(), exportVisibleTable(), openModal(), sortVisibleTable(), visibleTableFor()

### Community 56 - "UserFactory.php"
Cohesion: 0.28
Nodes (5): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Notifications\Notifiable, static

### Community 59 - "AppServiceProvider.php"
Cohesion: 0.29
Nodes (4): AppServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 60 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 61 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 62 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 66 - "AIServiceConfigurationTest.php"
Cohesion: 0.50
Nodes (3): Illuminate\Http\Client\ConnectionException, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\Log

### Community 67 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 68 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 69 - "DatabaseSeeder.php"
Cohesion: 0.60
Nodes (3): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder

### Community 70 - "Not Ready Verdict"
Cohesion: 0.40
Nodes (5): Live Operational Data Gap, Not Ready Verdict, CJP Southern Star OPC System and Audit Report, Final Turnover Gate, Turnover Readiness Checklist

### Community 73 - "require"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 74 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 77 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 78 - "autoload-dev"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 89 - "CJP Brand Logo"
Cohesion: 0.67
Nodes (3): CJP Brand Logo, CJP Monogram, Green Star Symbol

## Knowledge Gaps
- **66 isolated node(s):** `private`, `$schema`, `build`, `dev`, `type` (+61 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **52 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User` to `AIDataPreparationService`, `AdminMonitoringController`, `RoleBasedAccessControlTest`, `Illuminate\Support\Facades\DB`, `TestCase`, `Illuminate\Http\Request`, `PurchaseReceiptUploadTest`, `DashboardSummaryCardsTest`, `AdminUserManagementController`, `InventoryOfficerStockInTest`, `InventoryOfficerStockOutTest`, `RoleAccessTestingTest`, `SalesOfficerPaymentRecordingTest`, `SalesOfficerSalesManagementTest`, `DatabaseIntegrityTest`, `IntegrationTestingTest`, `AuthController.php`, `AIFeatureTestingTest`, `AIServiceConfigurationTest`, `CompleteWorkflowIntegrationTest`, `FormValidationTest`, `FunctionalTestingTest`, `Illuminate\Support\Str`, `AIDataPreparationServiceTest`, `AnalyticsTestingTest`, `BusinessInsightsTest`, `TurnoverReadinessTest`, `DispatchLiftingStatusManagementTest`, `InventoryVarianceExplanationsTest`, `SalesOfficerCustomerManagementTest`, `HaulTruckAssignmentTest`, `InventoryOfficerPurchaseManagementTest`, `RevenueInsightsTest`, `SalesOfficerReceivablesTrackingTest`, `PasswordResetCodeMail`, `AdminSalesReportsTest`, `InventoryLedgerTest`, `UserFactory.php`, `FrontendBugFixesTest`, `SalesTrendSummariesTest`, `AdminMonitoringTest`, `AIServiceConfigurationTest.php`, `MasterDataAndInventoryAccessTest`?**
  _High betweenness centrality (0.271) - this node is a cross-community bridge._
- **Why does `DashboardSummaryService` connect `DashboardSummaryService` to `AIDataPreparationService`, `InventoryOfficerPurchaseController`, `Illuminate\Support\Facades\DB`, `TestCase`, `Illuminate\Support\Collection`, `.salesRows`, `AuthController.php`?**
  _High betweenness centrality (0.109) - this node is a cross-community bridge._
- **Why does `TestCase` connect `TestCase` to `RoleBasedAccessControlTest`, `Illuminate\Support\Facades\DB`, `User`, `PurchaseReceiptUploadTest`, `DashboardSummaryCardsTest`, `InventoryOfficerStockInTest`, `InventoryOfficerStockOutTest`, `RoleAccessTestingTest`, `SalesOfficerPaymentRecordingTest`, `SalesOfficerSalesManagementTest`, `DatabaseIntegrityTest`, `IntegrationTestingTest`, `AuthController.php`, `AIFeatureTestingTest`, `AIServiceConfigurationTest`, `CompleteWorkflowIntegrationTest`, `FormValidationTest`, `FunctionalTestingTest`, `Illuminate\Support\Str`, `AIDataPreparationServiceTest`, `AnalyticsTestingTest`, `BusinessInsightsTest`, `TurnoverReadinessTest`, `DispatchLiftingStatusManagementTest`, `InventoryVarianceExplanationsTest`, `SalesOfficerCustomerManagementTest`, `HaulTruckAssignmentTest`, `InventoryOfficerPurchaseManagementTest`, `RevenueInsightsTest`, `SalesOfficerReceivablesTrackingTest`, `AdminSalesReportsTest`, `InventoryLedgerTest`, `FrontendBugFixesTest`, `SalesTrendSummariesTest`, `AdminMonitoringTest`, `AIServiceConfigurationTest.php`, `MasterDataAndInventoryAccessTest`, `ExampleTest`?**
  _High betweenness centrality (0.090) - this node is a cross-community bridge._
- **What connects `private`, `$schema`, `build` to the rest of the system?**
  _66 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `AIDataPreparationService` be split into smaller, more focused modules?**
  _Cohesion score 0.05030864197530864 - nodes in this community are weakly interconnected._
- **Should `DashboardSummaryService` be split into smaller, more focused modules?**
  _Cohesion score 0.08018648018648018 - nodes in this community are weakly interconnected._
- **Should `InventoryOfficerPurchaseController` be split into smaller, more focused modules?**
  _Cohesion score 0.09085213032581453 - nodes in this community are weakly interconnected._