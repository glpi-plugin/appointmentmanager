<?php
define('PLUGIN_APPOINTMENTMANAGER_VERSION', '1.0.0');

function plugin_version_appointmentmanager() {
    return [
        'name'         => 'Appointment Manager',
        'version'      => PLUGIN_APPOINTMENTMANAGER_VERSION,
        'author'       => 'DSI',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => ['glpi' => ['min' => '11.0.0']],
    ];
}

function plugin_appointmentmanager_check_prerequisites() {
    return true;
}

function plugin_appointmentmanager_check_config() {
    return true;
}

function plugin_init_appointmentmanager() {
    global $PLUGIN_HOOKS;

    Plugin::loadLang('appointmentmanager');

    $PLUGIN_HOOKS['csrf_compliant']['appointmentmanager'] = true;
    $PLUGIN_HOOKS['config_page']['appointmentmanager']    = 'front/config.php';

    Plugin::registerClass('PluginAppointmentmanagerAppointment');
    Plugin::registerClass('PluginAppointmentmanagerAppointmentType');
    Plugin::registerClass('PluginAppointmentmanagerAvailability');
    Plugin::registerClass('PluginAppointmentmanagerBlockedPeriod');
    Plugin::registerClass('PluginAppointmentmanagerConfig');
    Plugin::registerClass('PluginAppointmentmanagerProfile', ['addtabon' => 'Profile']);
    Plugin::registerClass('PluginAppointmentmanagerOAuthProvider');
    Plugin::registerClass('PluginAppointmentmanagerGoogleProvider');
    Plugin::registerClass('PluginAppointmentmanagerMicrosoftProvider');
    Plugin::registerClass('PluginAppointmentmanagerCalendarSync');

    if (Session::haveRight('plugin_appointmentmanager_appointment', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['appointmentmanager'] = ['tools' => 'PluginAppointmentmanagerConfig'];
    }

    if (Session::haveRight('plugin_appointmentmanager_appointment', CREATE)) {
        $PLUGIN_HOOKS['timeline_actions']['appointmentmanager'] = 'plugin_appointmentmanager_timeline_actions';
    }

    if (
        Session::haveRight('plugin_appointmentmanager_appointment', UPDATE)
        || Session::haveRight('config', UPDATE)
    ) {
        if (!isset($PLUGIN_HOOKS['menu_toadd']['appointmentmanager'])) {
            $PLUGIN_HOOKS['menu_toadd']['appointmentmanager'] = [];
        }
        $PLUGIN_HOOKS['menu_toadd']['appointmentmanager']['config'] = 'PluginAppointmentmanagerConfig';
    }
}

function plugin_appointmentmanager_timeline_actions(array $params): void {
    $item = $params['item'] ?? null;
    $rand = (int)($params['rand'] ?? mt_rand());

    if (!($item instanceof Ticket)) {
        return;
    }
    if (!Session::haveRight('plugin_appointmentmanager_appointment', CREATE)) {
        return;
    }

    PluginAppointmentmanagerAppointment::showTimelineButton($item, $rand);
}
