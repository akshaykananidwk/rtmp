<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth;
use App\Http\Controllers\Public\PublicController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- public site
Route::middleware('installed')->group(function (): void {
    Route::get('/', [PublicController::class, 'home'])->name('home');
    Route::get('/features', [PublicController::class, 'features'])->name('features');
    Route::get('/how-it-works', [PublicController::class, 'howItWorks'])->name('how-it-works');
    Route::get('/platforms', [PublicController::class, 'platforms'])->name('platforms');
    Route::get('/pricing', [PublicController::class, 'pricing'])->name('pricing');
    Route::get('/faq', [PublicController::class, 'faq'])->name('faq');
    Route::get('/contact', [PublicController::class, 'contact'])->name('contact');
    Route::get('/sitemap.xml', [PublicController::class, 'sitemap'])->name('sitemap');
    Route::get('/robots.txt', [PublicController::class, 'robots'])->name('robots');

    // ------------------------------------------------------------ auth
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [Auth\LoginController::class, 'show'])->name('login');
        Route::post('/login', [Auth\LoginController::class, 'login'])->middleware('throttle:login');
        Route::get('/admin/login', fn () => redirect()->route('login'))->name('admin.login');
        Route::get('/forgot-password', [Auth\PasswordResetController::class, 'request'])->name('password.request');
        Route::post('/forgot-password', [Auth\PasswordResetController::class, 'email'])->middleware('throttle:password-reset')->name('password.email');
        Route::get('/reset-password/{token}', [Auth\PasswordResetController::class, 'reset'])->name('password.reset');
        Route::post('/reset-password', [Auth\PasswordResetController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');
        Route::get('/two-factor', [Auth\TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
        Route::post('/two-factor', [Auth\TwoFactorController::class, 'verify'])->middleware('throttle:login');
    });
    Route::post('/logout', [Auth\LoginController::class, 'logout'])->middleware('auth')->name('logout');

    // ------------------------------------------------------------ admin panel
    Route::middleware(['auth', 'ip.allowlist'])->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/status', [Admin\DashboardController::class, 'status'])->middleware('throttle:status')->name('dashboard.status');

        // Live stream control
        Route::get('/live', [Admin\LiveController::class, 'index'])->name('live');
        Route::post('/live/start', [Admin\LiveController::class, 'start'])->name('live.start');
        Route::post('/live/stop', [Admin\LiveController::class, 'stop'])->name('live.stop');
        Route::post('/live/destination/{sessionDestination}/restart', [Admin\LiveController::class, 'restartDestination'])->name('live.destination.restart');
        Route::post('/live/destination/{sessionDestination}/stop', [Admin\LiveController::class, 'stopDestination'])->name('live.destination.stop');
        Route::get('/live/logs', [Admin\LiveController::class, 'logs'])->middleware('throttle:status')->name('live.logs');
        Route::get('/stream-test', [Admin\LiveController::class, 'streamTest'])->name('stream-test');

        // Stream keys / endpoints + OBS setup
        Route::resource('stream-keys', Admin\StreamKeyController::class)->except(['show']);
        Route::post('/stream-keys/{stream_key}/regenerate', [Admin\StreamKeyController::class, 'regenerate'])->name('stream-keys.regenerate');
        Route::post('/stream-keys/{stream_key}/revoke', [Admin\StreamKeyController::class, 'revoke'])->name('stream-keys.revoke');
        Route::post('/stream-keys/{stream_key}/toggle', [Admin\StreamKeyController::class, 'toggle'])->name('stream-keys.toggle');
        Route::post('/stream-keys/{stream_key}/reveal', [Admin\StreamKeyController::class, 'reveal'])->middleware('throttle:status')->name('stream-keys.reveal');
        Route::get('/obs-setup/{stream_key?}', [Admin\ObsSetupController::class, 'show'])->name('obs-setup');

        // Destinations
        Route::resource('destinations', Admin\DestinationController::class)->except(['show']);
        Route::post('/destinations/{destination}/test', [Admin\DestinationController::class, 'test'])->name('destinations.test');
        Route::post('/destinations/{destination}/toggle', [Admin\DestinationController::class, 'toggle'])->name('destinations.toggle');
        Route::get('/platforms/{platform}/connect', [Admin\OAuthController::class, 'redirect'])->name('platforms.connect');
        Route::get('/platforms/{platform}/callback', [Admin\OAuthController::class, 'callback'])->name('platforms.callback');
        Route::delete('/platforms/accounts/{account}', [Admin\OAuthController::class, 'disconnect'])->name('platforms.disconnect');

        // Overlays (news-style branding burned into the stream)
        Route::resource('overlays', Admin\OverlayController::class)->except(['show']);
        Route::post('/overlays/assign', [Admin\OverlayController::class, 'assign'])->name('overlays.assign');

        // Live preview (authenticated HLS proxy)
        Route::get('/preview/{endpoint}/status', [Admin\PreviewController::class, 'status'])->middleware('throttle:status')->name('preview.status');
        Route::get('/preview/{endpoint}/index.m3u8', [Admin\PreviewController::class, 'playlist'])->name('preview.playlist');
        Route::get('/preview/{endpoint}/{file}', [Admin\PreviewController::class, 'segment'])->name('preview.segment');

        // Schedules
        Route::resource('schedules', Admin\ScheduleController::class)->except(['show']);
        Route::post('/schedules/{schedule}/cancel', [Admin\ScheduleController::class, 'cancel'])->name('schedules.cancel');

        // Recordings
        Route::get('/recordings', [Admin\RecordingController::class, 'index'])->name('recordings.index');
        Route::get('/recordings/{recording}/download', [Admin\RecordingController::class, 'download'])->name('recordings.download');
        Route::delete('/recordings/{recording}', [Admin\RecordingController::class, 'destroy'])->name('recordings.destroy');

        // Analytics + history
        Route::get('/analytics', [Admin\AnalyticsController::class, 'index'])->name('analytics');
        Route::get('/history', [Admin\HistoryController::class, 'index'])->name('history.index');
        Route::get('/history/{session}', [Admin\HistoryController::class, 'show'])->name('history.show');

        // Users
        Route::resource('users', Admin\UserController::class)->except(['show']);
        Route::get('/profile', [Admin\ProfileController::class, 'edit'])->name('profile');
        Route::put('/profile', [Admin\ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [Admin\ProfileController::class, 'password'])->name('profile.password');
        Route::post('/profile/two-factor', [Admin\ProfileController::class, 'enableTwoFactor'])->name('profile.two-factor.enable');
        Route::post('/profile/two-factor/confirm', [Admin\ProfileController::class, 'confirmTwoFactor'])->name('profile.two-factor.confirm');
        Route::delete('/profile/two-factor', [Admin\ProfileController::class, 'disableTwoFactor'])->name('profile.two-factor.disable');
        Route::post('/profile/api-token', [Admin\ProfileController::class, 'createToken'])->name('profile.token.create');
        Route::delete('/profile/api-token/{tokenId}', [Admin\ProfileController::class, 'revokeToken'])->name('profile.token.revoke');
        Route::get('/notifications', [Admin\NotificationController::class, 'index'])->name('notifications');
        Route::post('/notifications/read', [Admin\NotificationController::class, 'markRead'])->name('notifications.read');

        // Backups
        Route::get('/backups', [Admin\BackupController::class, 'index'])->name('backups.index');
        Route::post('/backups', [Admin\BackupController::class, 'store'])->name('backups.store');
        Route::get('/backups/{backup}/download/{part}', [Admin\BackupController::class, 'download'])->name('backups.download');
        Route::post('/backups/{backup}/restore', [Admin\BackupController::class, 'restore'])->name('backups.restore');
        Route::post('/backups/{backup}/verify', [Admin\BackupController::class, 'verify'])->name('backups.verify');
        Route::delete('/backups/{backup}', [Admin\BackupController::class, 'destroy'])->name('backups.destroy');

        // Updates
        Route::get('/updates', [Admin\UpdateController::class, 'index'])->name('updates.index');
        Route::post('/updates/settings', [Admin\UpdateController::class, 'saveSettings'])->name('updates.settings');
        Route::post('/updates/check', [Admin\UpdateController::class, 'check'])->name('updates.check');
        Route::post('/updates/install', [Admin\UpdateController::class, 'install'])->name('updates.install');
        Route::post('/updates/recover', [Admin\UpdateController::class, 'recover'])->name('updates.recover');
        Route::get('/updates/{update}', [Admin\UpdateController::class, 'show'])->name('updates.show');
        Route::get('/updates/{update}/status', [Admin\UpdateController::class, 'status'])->middleware('throttle:status')->name('updates.status');
        Route::post('/updates/{update}/rollback', [Admin\UpdateController::class, 'rollback'])->name('updates.rollback');
        Route::post('/updates/protected-paths', [Admin\UpdateController::class, 'addProtectedPath'])->name('updates.protected.store');
        Route::delete('/updates/protected-paths/{path}', [Admin\UpdateController::class, 'removeProtectedPath'])->name('updates.protected.destroy');

        // Health, logs, settings, cron
        Route::get('/health', [Admin\HealthController::class, 'index'])->name('health');
        Route::post('/health/run', [Admin\HealthController::class, 'run'])->name('health.run');
        Route::get('/health/{service}', [Admin\HealthController::class, 'show'])->name('health.show');
        Route::get('/logs', [Admin\LogController::class, 'index'])->name('logs');
        Route::get('/logs/audit', [Admin\LogController::class, 'audit'])->name('logs.audit');
        Route::get('/logs/errors', [Admin\LogController::class, 'errors'])->name('logs.errors');
        Route::get('/logs/errors/{error}', [Admin\LogController::class, 'error'])->name('logs.error');
        Route::get('/settings', [Admin\SettingsController::class, 'index'])->name('settings');
        Route::post('/settings/{group}', [Admin\SettingsController::class, 'update'])->name('settings.update');
        Route::get('/cron-setup', [Admin\SettingsController::class, 'cron'])->name('cron');
    });
});
