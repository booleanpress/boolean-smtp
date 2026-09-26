<?php

/**
 * REST API route definitions for the plugin, loaded inside the `booleansmtp/v1` route group.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @var \BooleanSmtp\Core\Http\Router $router Loaded by RouteServiceProvider inside the
 *      `booleansmtp/v1` group, which applies the `auth` middleware and the admin capability
 *      to every route here; a route only declares `->can()` when it needs a finer capability.
 */

// Dashboard: summary stats, charts, recent failures, and onboarding progress.
$router->get('dashboard/stats', [\BooleanSmtp\Http\Controllers\DashboardController::class, 'stats']);
$router->get('dashboard/chart', [\BooleanSmtp\Http\Controllers\DashboardController::class, 'chart']);
$router->get('dashboard/activity', [\BooleanSmtp\Http\Controllers\DashboardController::class, 'recentActivity']);
$router->get('dashboard/onboarding', [\BooleanSmtp\Http\Controllers\DashboardController::class, 'getOnboarding']);
$router->post('dashboard/onboarding', [\BooleanSmtp\Http\Controllers\DashboardController::class, 'updateOnboarding']);
$router->post('dashboard/onboarding/apply', [\BooleanSmtp\Http\Controllers\DashboardController::class, 'apply']);

// Connections: CRUD, testing, credential verification, and OAuth token management.
$router->get('connections', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'index']);
$router->post('connections', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'store']);
$router->get('connections/{id}', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'show']);
$router->put('connections/{id}', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'update']);
$router->delete('connections/{id}', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'destroy']);
$router->delete('connections', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'bulkDestroy']);
$router->post('connections/{id}/test', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'test']);
$router->post('connections/{id}/verify-credentials', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'verifyCredentials']);
$router->post('connections/{id}/verify-api-credentials', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'verifyApiCredentials']);
$router->post('connections/{id}/oauth-token', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'saveOAuthToken']);
$router->post('connections/{id}/oauth-stage', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'stageOAuthConnection']);
$router->post('connections/{id}/oauth-finalize', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'finalizeOAuthConnection']);
$router->post('connections/{id}/oauth-refresh-now', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'refreshOAuthNow']);
$router->get('connections/{id}/oauth-refresh-history', [\BooleanSmtp\Http\Controllers\ConnectionController::class, 'getOAuthRefreshHistory']);

// Transports: settings schema, validation rules, and presets for each mail transport.
$router->get('transports', [\BooleanSmtp\Http\Controllers\TransportController::class, 'index']);
$router->get('transports/{driver}', [\BooleanSmtp\Http\Controllers\TransportController::class, 'show']);

// OAuth (Google / Microsoft) — register redirect URIs in cloud consoles to match get_rest_url(..., 'booleansmtp/v1/oauth/.../callback')
$router->get('oauth/google/authorize', [\BooleanSmtp\Http\Controllers\OAuthController::class, 'googleAuthorize']);
$router->get('oauth/google/callback', [\BooleanSmtp\Http\Controllers\OAuthController::class, 'googleCallback']);
$router->get('oauth/microsoft/authorize', [\BooleanSmtp\Http\Controllers\OAuthController::class, 'microsoftAuthorize']);
$router->get('oauth/microsoft/callback', [\BooleanSmtp\Http\Controllers\OAuthController::class, 'microsoftCallback']);

// Email logs: browsing, viewing, resending, and deleting logged emails.
$router->get('logs', [\BooleanSmtp\Http\Controllers\EmailLogController::class, 'index']);
$router->get('logs/queue/stats', [\BooleanSmtp\Http\Controllers\EmailLogController::class, 'queueStats']);
$router->get('logs/{id}', [\BooleanSmtp\Http\Controllers\EmailLogController::class, 'show']);
$router->post('logs/resend', [\BooleanSmtp\Http\Controllers\EmailLogController::class, 'bulkResend']);
$router->post('logs/{id}/resend', [\BooleanSmtp\Http\Controllers\EmailLogController::class, 'resend']);
$router->delete('logs/{id}', [\BooleanSmtp\Http\Controllers\EmailLogController::class, 'destroy']);
$router->delete('logs', [\BooleanSmtp\Http\Controllers\EmailLogController::class, 'bulkDestroy']);

// Test email: sends a real test message and reports how it was delivered.
$router->post('test-email', [\BooleanSmtp\Http\Controllers\TestEmailController::class, 'send']);

// Settings: reads and updates the plugin's global settings record.
$router->get('settings', [\BooleanSmtp\Http\Controllers\SettingsController::class, 'index']);
$router->put('settings', [\BooleanSmtp\Http\Controllers\SettingsController::class, 'update']);

// Alerts: one setup per provider (Telegram, Slack, Discord), keyed by the provider type.
$router->get('notifications', [\BooleanSmtp\Http\Controllers\NotificationController::class, 'index']);
$router->post('notifications/telegram/detect-chats', [\BooleanSmtp\Http\Controllers\NotificationController::class, 'detectTelegramChats']);
$router->put('notifications/{type}', [\BooleanSmtp\Http\Controllers\NotificationController::class, 'update']);
$router->delete('notifications/{type}', [\BooleanSmtp\Http\Controllers\NotificationController::class, 'destroy']);
$router->post('notifications/{type}/test', [\BooleanSmtp\Http\Controllers\NotificationController::class, 'test']);

// Tools: debug logs, the active-plugin list, and migration from other SMTP plugins.
$router->get('tools/debug-logs', [\BooleanSmtp\Http\Controllers\ToolsController::class, 'debugLogs']);
$router->delete('tools/debug-logs', [\BooleanSmtp\Http\Controllers\ToolsController::class, 'clearDebugLogs']);
$router->get('tools/plugins', [\BooleanSmtp\Http\Controllers\ToolsController::class, 'activePlugins']);
$router->get('tools/migration/scan', [\BooleanSmtp\Http\Controllers\ToolsController::class, 'migrationScan']);
$router->post('tools/migration/import', [\BooleanSmtp\Http\Controllers\ToolsController::class, 'migrationImport']);
