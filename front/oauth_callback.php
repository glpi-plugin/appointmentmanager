<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

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

$session_data = $_SESSION['am_oauth'] ?? [];
unset($_SESSION['am_oauth']);

if (
    empty($session_data['state'])
    || $session_data['state'] !== $state
    || $session_data['provider'] !== $provider
) {
    Session::addMessageAfterRedirect(__('Invalid OAuth state. Please try again.', 'appointmentmanager'), false, ERROR);
    Html::redirect($integrations);
}

$users_id = (int)$session_data['users_id'];
if ($users_id !== (int)Session::getLoginUserID()) {
    Session::addMessageAfterRedirect(__('Session mismatch. Please try again.', 'appointmentmanager'), false, ERROR);
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
