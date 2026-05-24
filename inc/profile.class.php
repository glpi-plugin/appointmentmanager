<?php
class PluginAppointmentmanagerProfile extends CommonDBTM {

    public static $rightname = 'config';

    static function getTypeName($nb = 0) {
        return __('Appointment Manager', 'appointmentmanager');
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Profile) {
            return self::getTypeName();
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item instanceof Profile) {
            self::showProfileForm($item);
        }
        return true;
    }

    static function showProfileForm(Profile $profile) {
        $canedit = Session::haveRight('config', UPDATE);
        $rights  = self::getAllRights();
        $ID      = $profile->getID();

        echo '<form method="POST" action="' . htmlspecialchars(Profile::getFormURL(), ENT_QUOTES, 'UTF-8') . '">';
        echo Html::hidden('id', ['value' => $ID]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        echo '<div class="p-3">';
        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('Appointment Manager plugin', 'appointmentmanager'),
        ]);
        if ($canedit) {
            echo '<div class="mt-3 text-center">';
            echo '<button type="submit" name="update" class="btn btn-primary">'
                . _sx('button', 'Save') . '</button>';
            echo '</div>';
        }
        echo '</div>';

        echo '</form>';
    }

    static function getAllRights($all = false) {
        return [
            [
                'itemtype' => 'PluginAppointmentmanagerAppointment',
                'label'    => __('Manage appointments', 'appointmentmanager'),
                'field'    => 'plugin_appointmentmanager_appointment',
                'rights'   => [READ => __('Read'), CREATE => __('Create'), UPDATE => __('Update'), DELETE => __('Delete')],
            ],
            [
                'itemtype' => 'PluginAppointmentmanagerAppointmentType',
                'label'    => __('Manage appointment types', 'appointmentmanager'),
                'field'    => 'plugin_appointmentmanager_type',
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
            ],
            [
                'itemtype' => 'PluginAppointmentmanagerAvailability',
                'label'    => __('Manage own availability', 'appointmentmanager'),
                'field'    => 'plugin_appointmentmanager_availability',
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
            ],
        ];
    }

    static function addDefaultProfileRights() {
        global $DB;

        // Create the right fields if they don't exist
        $rights_exist = $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['name' => 'plugin_appointmentmanager_appointment'],
            'LIMIT' => 1,
        ]);
        if ($rights_exist->count() === 0) {
            ProfileRight::addProfileRights([
                'plugin_appointmentmanager_appointment',
                'plugin_appointmentmanager_type',
                'plugin_appointmentmanager_availability',
            ]);
        }

        // Grant rights to Super-Admin
        $super_admin_result = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_profiles',
            'WHERE'  => ['name' => 'Super-Admin'],
            'LIMIT'  => 1,
        ]);
        if ($super_admin_result->count() === 0) {
            return;
        }

        $super_admin_id = (int)$super_admin_result->current()['id'];

        $fields = [
            'plugin_appointmentmanager_appointment',
            'plugin_appointmentmanager_type',
            'plugin_appointmentmanager_availability',
        ];

        foreach ($fields as $field) {
            $existing = $DB->request([
                'FROM'  => 'glpi_profilerights',
                'WHERE' => ['profiles_id' => $super_admin_id, 'name' => $field],
                'LIMIT' => 1,
            ]);

            if ($existing->count() > 0) {
                $rights = ($field === 'plugin_appointmentmanager_appointment')
                    ? (READ | CREATE | UPDATE | DELETE)
                    : (READ | UPDATE);
                $DB->update('glpi_profilerights', [
                    'rights' => $rights,
                ], [
                    'profiles_id' => $super_admin_id,
                    'name'        => $field,
                ]);
            }
        }
    }

    static function removeRights() {
        ProfileRight::deleteProfileRights([
            'plugin_appointmentmanager_appointment',
            'plugin_appointmentmanager_type',
            'plugin_appointmentmanager_availability',
        ]);
    }
}
