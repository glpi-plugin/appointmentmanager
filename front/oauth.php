<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

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

$state = bin2hex(random_bytes(16));
$_SESSION['am_oauth'] = [
    'state'    => $state,
    'provider' => $provider,
    'users_id' => (int)Session::getLoginUserID(),
];

$instance  = PluginAppointmentmanagerOAuthProvider::getProviderInstance($provider);
$auth_url  = $instance->getAuthUrl($state);

Html::redirect($auth_url);
