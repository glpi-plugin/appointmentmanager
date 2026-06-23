<?php
include('../../../inc/includes.php');

// No Session::checkLoginUser() here — the state token authenticates the callback.
// GLPI sets SameSite=Strict on its session cookie, so the browser drops it when
// Microsoft redirects back to this page (cross-site redirect). Requiring a session
// forces the user to re-login mid-flow. The state token (128-bit random, 10-min
// expiry, single-use) is sufficient proof that this callback was initiated by the
// user who started the OAuth flow.

global $DB;

$plugin_url   = Plugin::getWebDir('appointmentmanager', true);
$integrations = $plugin_url . '/front/config.php?tab=integrations';

$provider = trim($_GET['provider'] ?? '');
$code     = trim($_GET['code']     ?? '');
$state    = trim($_GET['state']    ?? '');
$error    = trim($_GET['error']    ?? '');

$has_session = (bool)Session::getLoginUserID();

if ($error) {
    if ($has_session) {
        Session::addMessageAfterRedirect(
            sprintf(__('Calendar authorization was denied: %s', 'appointmentmanager'), htmlspecialchars($error, ENT_QUOTES, 'UTF-8')),
            false,
            WARNING
        );
    }
    Html::redirect($integrations);
}

$pending_row = null;

if ($state && in_array($provider, ['google', 'microsoft'], true)) {
    $iter = $DB->request([
        'FROM'  => 'glpi_plugin_appointmentmanager_oauth_pending',
        'WHERE' => ['provider' => $provider, 'state' => $state],
        'LIMIT' => 1,
    ]);
    $pending_row = $iter->count() > 0 ? $iter->current() : null;
}

if ($pending_row) {
    $DB->delete('glpi_plugin_appointmentmanager_oauth_pending', ['id' => (int)$pending_row['id']]);
}

if (!$pending_row) {
    if ($has_session) {
        Session::addMessageAfterRedirect(__('Invalid OAuth state. Please try again.', 'appointmentmanager'), false, ERROR);
    }
    Html::redirect($integrations);
}

if (strtotime($pending_row['expires_at']) < time()) {
    if ($has_session) {
        Session::addMessageAfterRedirect(__('OAuth session expired. Please try again.', 'appointmentmanager'), false, ERROR);
    }
    Html::redirect($integrations);
}

if (!in_array($provider, ['google', 'microsoft'], true) || !$code) {
    if ($has_session) {
        Session::addMessageAfterRedirect(__('Invalid callback parameters.', 'appointmentmanager'), false, ERROR);
    }
    Html::redirect($integrations);
}

$users_id = (int)$pending_row['users_id'];

try {
    $instance = PluginAppointmentmanagerOAuthProvider::getProviderInstance($provider);
    $tokens   = $instance->exchangeCode($code);
    PluginAppointmentmanagerOAuthProvider::saveToken(
        $users_id,
        $provider,
        $tokens['access_token'],
        $tokens['refresh_token'],
        (int)($tokens['expires_in'] ?? 3600)
    );
    if ($has_session) {
        $provider_label = $provider === 'google' ? 'Google Calendar' : 'Microsoft Outlook';
        Session::addMessageAfterRedirect(
            sprintf(__('%s connected successfully.', 'appointmentmanager'), $provider_label),
            false,
            INFO
        );
    }
} catch (Throwable $e) {
    error_log('[appointmentmanager] oauth_callback error: ' . $e->getMessage());
    if ($has_session) {
        Session::addMessageAfterRedirect(__('Failed to connect calendar. Please try again.', 'appointmentmanager'), false, ERROR);
    }
}

Html::redirect($integrations);
