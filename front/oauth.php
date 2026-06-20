<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Session::haveRight('plugin_appointmentmanager_calendar', READ)) {
    Html::displayRightError();
    exit;
}

$provider = trim($_GET['provider'] ?? '');
if (!in_array($provider, ['google', 'microsoft'], true)) {
    Html::header(__('Calendar integration', 'appointmentmanager'), $_SERVER['PHP_SELF'], 'tools', 'PluginAppointmentmanagerConfig');
    echo '<div class="container mt-4"><div class="alert alert-danger">' . __('Invalid provider.', 'appointmentmanager') . '</div></div>';
    Html::footer();
    exit;
}

$settings = PluginAppointmentmanagerOAuthProvider::getSettings($provider);
if (!$settings) {
    Html::header(__('Calendar integration', 'appointmentmanager'), $_SERVER['PHP_SELF'], 'tools', 'PluginAppointmentmanagerConfig');
    echo '<div class="container mt-4"><div class="alert alert-warning">'
        . __('This calendar provider is not configured. Please contact your administrator.', 'appointmentmanager')
        . '</div></div>';
    Html::footer();
    exit;
}

global $DB;

$state    = bin2hex(random_bytes(16));
$users_id = (int)Session::getLoginUserID();

$DB->delete('glpi_plugin_appointmentmanager_oauth_pending', ['users_id' => $users_id, 'provider' => $provider]);
$DB->insert('glpi_plugin_appointmentmanager_oauth_pending', [
    'users_id'   => $users_id,
    'provider'   => $provider,
    'state'      => $state,
    'expires_at' => date('Y-m-d H:i:s', time() + 600),
]);

$instance  = PluginAppointmentmanagerOAuthProvider::getProviderInstance($provider);
$auth_url  = $instance->getAuthUrl($state);

Html::redirect($auth_url);
