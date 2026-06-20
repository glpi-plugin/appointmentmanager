<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Session::haveRight('plugin_appointmentmanager_calendar', READ)) {
    Html::displayRightError();
    exit;
}

$plugin_url   = Plugin::getWebDir('appointmentmanager', true);
$integrations = $plugin_url . '/front/config.php?tab=integrations';

$provider   = trim($_GET['provider'] ?? '');
$code       = trim($_GET['code']     ?? '');
$state      = trim($_GET['state']    ?? '');
$error      = trim($_GET['error']    ?? '');

if ($error) {
    Session::addMessageAfterRedirect(
        sprintf(__('Calendar authorization was denied: %s', 'appointmentmanager'), htmlspecialchars($error, ENT_QUOTES, 'UTF-8')),
        false,
        WARNING
    );
    Html::redirect($integrations);
}

global $DB;

$users_id    = (int)Session::getLoginUserID();
$pending_row = null;

if ($state && in_array($provider, ['google', 'microsoft'], true)) {
    $iter = $DB->request([
        'FROM'  => 'glpi_plugin_appointmentmanager_oauth_pending',
        'WHERE' => ['users_id' => $users_id, 'provider' => $provider, 'state' => $state],
        'LIMIT' => 1,
    ]);
    $pending_row = $iter->count() > 0 ? $iter->current() : null;
}

$DB->delete('glpi_plugin_appointmentmanager_oauth_pending', ['users_id' => $users_id, 'provider' => $provider]);

if (!$pending_row) {
    Session::addMessageAfterRedirect(__('Invalid OAuth state. Please try again.', 'appointmentmanager'), false, ERROR);
    Html::redirect($integrations);
}

if (strtotime($pending_row['expires_at']) < time()) {
    Session::addMessageAfterRedirect(__('OAuth session expired. Please try again.', 'appointmentmanager'), false, ERROR);
    Html::redirect($integrations);
}

if (!in_array($provider, ['google', 'microsoft'], true) || !$code) {
    Session::addMessageAfterRedirect(__('Invalid callback parameters.', 'appointmentmanager'), false, ERROR);
    Html::redirect($integrations);
}

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
    $provider_label = $provider === 'google' ? 'Google Calendar' : 'Microsoft Outlook';
    Session::addMessageAfterRedirect(
        sprintf(__('%s connected successfully.', 'appointmentmanager'), $provider_label),
        false,
        INFO
    );
} catch (Throwable $e) {
    error_log('[appointmentmanager] oauth_callback error: ' . $e->getMessage());
    Session::addMessageAfterRedirect(__('Failed to connect calendar. Please try again.', 'appointmentmanager'), false, ERROR);
}

Html::redirect($integrations);
