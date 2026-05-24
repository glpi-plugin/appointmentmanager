<?php
class PluginAppointmentmanagerConfig {

    public static $rightname = 'plugin_appointmentmanager_appointment';

    static function getTypeName($nb = 0) {
        return __('Appointment Manager', 'appointmentmanager');
    }

    static function getMenuName() {
        return __('Appointments', 'appointmentmanager');
    }

    static function getMenuContent() {
        if (!Session::haveRight('plugin_appointmentmanager_appointment', READ)) {
            return false;
        }

        $menu = [
            'title' => self::getMenuName(),
            'page'  => '/plugins/appointmentmanager/front/calendar.php',
            'icon'  => 'ti ti-calendar',
            'links' => [
                __('Calendar', 'appointmentmanager')  => '/plugins/appointmentmanager/front/calendar.php',
            ],
        ];

        if (Session::haveRight('plugin_appointmentmanager_appointment', UPDATE) || Session::haveRight('config', UPDATE)) {
            $menu['links'][__('Settings', 'appointmentmanager')] = '/plugins/appointmentmanager/front/config.php';
        }

        return $menu;
    }
}
