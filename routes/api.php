<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ApiSourceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\SiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public auth endpoints (session established via Sanctum SPA cookies)
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login/mfa', [AuthController::class, 'loginMfa']);

/*
|--------------------------------------------------------------------------
| Authenticated endpoints
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'track'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/password', [PasswordController::class, 'update']);

    // Multi-factor authentication management
    Route::post('/mfa/setup', [MfaController::class, 'setup']);
    Route::post('/mfa/confirm', [MfaController::class, 'confirm']);
    Route::post('/mfa/disable', [MfaController::class, 'disable']);
    Route::post('/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes']);

    Route::get('/health', [HealthController::class, 'index']);

    // Connector sources & data
    Route::get('/sources/presets', [ApiSourceController::class, 'presets']);
    Route::post('/sources/test', [ApiSourceController::class, 'test']);
    Route::get('/sources', [ApiSourceController::class, 'index']);
    Route::post('/sources', [ApiSourceController::class, 'store']);
    Route::get('/sources/{source}', [ApiSourceController::class, 'show']);
    Route::put('/sources/{source}', [ApiSourceController::class, 'update']);
    Route::delete('/sources/{source}', [ApiSourceController::class, 'destroy']);
    Route::post('/sources/{source}/refresh', [ApiSourceController::class, 'refresh']);
    Route::post('/sources/{source}/unlock', [ApiSourceController::class, 'unlock']);
    Route::get('/sources/{source}/runs', [ApiSourceController::class, 'runs']);

    Route::get('/sources/{source}/data', [DataController::class, 'index']);
    Route::get('/sources/{source}/summary', [DataController::class, 'summary']);
    Route::get('/sources/{source}/aggregate', [DataController::class, 'aggregate']);
    Route::get('/sources/{source}/trends', [DataController::class, 'trends']);

    // Sites (groupings) + scope-aware insights (all sites / a site / a source)
    Route::get('/sites', [SiteController::class, 'index']);
    Route::post('/sites', [SiteController::class, 'store']);
    Route::put('/sites/{site}', [SiteController::class, 'update']);
    Route::delete('/sites/{site}', [SiteController::class, 'destroy']);
    Route::post('/sites/{site}/assign', [SiteController::class, 'assign']);

    Route::get('/insights/summary', [InsightsController::class, 'summary']);
    Route::get('/insights/aggregate', [InsightsController::class, 'aggregate']);
    Route::get('/insights/trends', [InsightsController::class, 'trends']);
    Route::get('/insights/data', [InsightsController::class, 'data']);
    Route::post('/insights/evaluate', [InsightsController::class, 'evaluate']);

    // Dashboards (per-user, persisted)
    Route::get('/dashboards', [DashboardController::class, 'index']);
    Route::get('/dashboards/default', [DashboardController::class, 'default']);
    Route::post('/dashboards', [DashboardController::class, 'store']);
    Route::get('/dashboards/{dashboard}', [DashboardController::class, 'show']);
    Route::put('/dashboards/{dashboard}', [DashboardController::class, 'update']);
    Route::delete('/dashboards/{dashboard}', [DashboardController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | Admin CMS (admin role only)
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users', [AdminController::class, 'store']);
        Route::get('/users/{user}', [AdminController::class, 'show']);
        Route::put('/users/{user}', [AdminController::class, 'update']);
        Route::post('/users/{user}/reset-password', [AdminController::class, 'resetPassword']);
        Route::post('/users/{user}/clear-ip-flag', [AdminController::class, 'clearIpFlag']);
        Route::post('/users/{user}/unlock', [AdminController::class, 'unlock']);
        Route::post('/users/{user}/disable-mfa', [AdminController::class, 'disableMfa']);
        Route::get('/login-events', [AdminController::class, 'loginEvents']);
        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
    });
});
