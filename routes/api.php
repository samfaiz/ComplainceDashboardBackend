<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ApiSourceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\SiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public auth endpoints (session established via Sanctum SPA cookies)
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login/mfa', [AuthController::class, 'loginMfa']);

// "Try it" — spin up a throwaway demo workspace and auto sign-in. Rate-limited
// (≈5 per hour per IP) since it is public and creates data.
Route::post('/demo', [DemoController::class, 'store'])->middleware('throttle:5,60');

/*
|--------------------------------------------------------------------------
| Authenticated endpoints
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'active', 'demo.active', 'tenant', 'track', 'password.changed', 'mfa.enrolled'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/password', [PasswordController::class, 'update']);
    Route::post('/account/reset-request', [AccountController::class, 'requestReset']);

    // Multi-factor authentication management
    Route::post('/mfa/setup', [MfaController::class, 'setup']);
    Route::post('/mfa/confirm', [MfaController::class, 'confirm']);
    Route::post('/mfa/disable', [MfaController::class, 'disable']);
    Route::post('/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes']);

    Route::get('/health', [HealthController::class, 'index']);
    Route::get('/health/stack', [HealthController::class, 'techStack']);

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
    Route::post('/insights/rule-data', [InsightsController::class, 'ruleData']);

    // Endpoint table column layout (available fields + shared default + personal override)
    Route::get('/endpoint-columns', [InsightsController::class, 'columns']);
    Route::put('/endpoint-columns', [InsightsController::class, 'saveColumns']);
    Route::delete('/endpoint-columns', [InsightsController::class, 'resetColumns']);

    // My notification subscriptions
    Route::get('/notification-subscriptions', [NotificationController::class, 'mySubscriptions']);
    Route::put('/notification-subscriptions', [NotificationController::class, 'updateMySubscriptions']);

    // Dashboards (per-user, persisted)
    Route::get('/dashboards', [DashboardController::class, 'index']);
    Route::get('/dashboards/default', [DashboardController::class, 'default']);
    Route::post('/dashboards', [DashboardController::class, 'store']);
    Route::get('/dashboards/{dashboard}', [DashboardController::class, 'show']);
    Route::put('/dashboards/{dashboard}', [DashboardController::class, 'update']);
    Route::delete('/dashboards/{dashboard}', [DashboardController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | Platform owner (super_admin) — cross-organization management
    |----------------------------------------------------------------------
    */
    Route::middleware('role:super_admin')->prefix('platform')->group(function () {
        Route::get('/organizations', [PlatformController::class, 'organizations']);
        Route::post('/organizations', [PlatformController::class, 'store']);
        Route::get('/organizations/{organization}', [PlatformController::class, 'show']);
        Route::put('/organizations/{organization}', [PlatformController::class, 'update']);
        Route::delete('/organizations/{organization}', [PlatformController::class, 'destroy']);
        Route::post('/organizations/{organization}/enter', [PlatformController::class, 'enter']);
        Route::post('/exit', [PlatformController::class, 'exit']);
    });

    /*
    |----------------------------------------------------------------------
    | Admin CMS (admin role only)
    |----------------------------------------------------------------------
    */
    Route::middleware('role:super_admin,admin')->prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users', [AdminController::class, 'store']);
        Route::get('/users/{user}', [AdminController::class, 'show']);
        Route::put('/users/{user}', [AdminController::class, 'update']);
        Route::post('/users/{user}/reset-password', [AdminController::class, 'resetPassword']);
        Route::post('/users/{user}/clear-ip-flag', [AdminController::class, 'clearIpFlag']);
        Route::post('/users/{user}/unlock', [AdminController::class, 'unlock']);
        Route::post('/users/{user}/disable-mfa', [AdminController::class, 'disableMfa']);
        Route::put('/users/{user}/mfa-required', [AdminController::class, 'setMfaRequired']);
        Route::delete('/users/{user}', [AdminController::class, 'destroy']);
        Route::get('/reset-requests', [AdminController::class, 'resetRequests']);
        Route::post('/reset-requests/{accountRequest}/dismiss', [AdminController::class, 'dismissRequest']);
        Route::get('/login-events', [AdminController::class, 'loginEvents']);
        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);

        // Dashboard assignment
        Route::get('/dashboards', [AdminController::class, 'dashboards']);
        Route::get('/users/{user}/dashboards', [AdminController::class, 'userDashboards']);
        Route::post('/users/{user}/dashboards', [AdminController::class, 'assignDashboard']);
        Route::delete('/users/{user}/dashboards/{dashboard}', [AdminController::class, 'unassignDashboard']);

        // Notifications
        Route::get('/mail-settings', [NotificationController::class, 'getMailSettings']);
        Route::put('/mail-settings', [NotificationController::class, 'updateMailSettings']);
        Route::post('/mail-settings/test', [NotificationController::class, 'testMail']);

        Route::get('/notification-templates', [NotificationController::class, 'listTemplates']);
        Route::put('/notification-templates/{template}', [NotificationController::class, 'updateTemplate']);
        Route::post('/notification-templates/{template}/reset', [NotificationController::class, 'resetTemplate']);
        Route::post('/notification-templates/{template}/preview', [NotificationController::class, 'previewTemplate']);
        Route::post('/notification-templates/{template}/test', [NotificationController::class, 'testTemplate']);

        Route::get('/notification-logs', [NotificationController::class, 'logs']);

        Route::get('/users/{user}/notification-subscriptions', [NotificationController::class, 'userSubscriptions']);
        Route::put('/users/{user}/notification-subscriptions', [NotificationController::class, 'updateUserSubscriptions']);
    });
});
