<?php
function plugin_appointmentmanager_install() {
    global $DB;

    $migration = new Migration(PLUGIN_APPOINTMENTMANAGER_VERSION);

    if (!$DB->tableExists('glpi_plugin_appointmentmanager_appointmenttypes')) {
        $migration->addPostQuery("CREATE TABLE `glpi_plugin_appointmentmanager_appointmenttypes` (
            `id`            int(11)      NOT NULL AUTO_INCREMENT,
            `name`          varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `color`         varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0055a4',
            `icon`          varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `is_active`     tinyint(1)   NOT NULL DEFAULT 1,
            `is_default`    tinyint(1)   NOT NULL DEFAULT 0,
            `sort_order`    int(11)      NOT NULL DEFAULT 0,
            `date_creation` datetime     NOT NULL,
            `date_mod`      datetime     NOT NULL,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$DB->tableExists('glpi_plugin_appointmentmanager_appointments')) {
        $migration->addPostQuery("CREATE TABLE `glpi_plugin_appointmentmanager_appointments` (
            `id`                  int(11)      NOT NULL AUTO_INCREMENT,
            `tickets_id`          int(11)      NOT NULL DEFAULT 0,
            `users_id_tech`       int(11)      NOT NULL DEFAULT 0,
            `users_id_requester`  int(11)      NOT NULL DEFAULT 0,
            `appointmenttypes_id` int(11)      NOT NULL DEFAULT 0,
            `status`              varchar(30)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'proposed',
            `date_start`          datetime     NOT NULL,
            `date_end`            datetime     NOT NULL,
            `locations_id`        int(11)      NOT NULL DEFAULT 0,
            `comment`             text         COLLATE utf8mb4_unicode_ci,
            `confirm_token`       varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `date_creation`       datetime     NOT NULL,
            `date_mod`            datetime     NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `confirm_token` (`confirm_token`),
            KEY `tickets_id`         (`tickets_id`),
            KEY `users_id_tech`      (`users_id_tech`),
            KEY `users_id_requester` (`users_id_requester`),
            KEY `status`             (`status`),
            KEY `date_start`         (`date_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$DB->tableExists('glpi_plugin_appointmentmanager_availability')) {
        $migration->addPostQuery("CREATE TABLE `glpi_plugin_appointmentmanager_availability` (
            `id`          int(11)    NOT NULL AUTO_INCREMENT,
            `users_id`    int(11)    NOT NULL DEFAULT 0,
            `day_of_week` tinyint(1) NOT NULL DEFAULT 0,
            `time_start`  time       NOT NULL DEFAULT '08:00:00',
            `time_end`    time       NOT NULL DEFAULT '18:00:00',
            `is_active`   tinyint(1) NOT NULL DEFAULT 1,
            `date_mod`    datetime   NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_day` (`users_id`, `day_of_week`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$DB->tableExists('glpi_plugin_appointmentmanager_blockedperiods')) {
        $migration->addPostQuery("CREATE TABLE `glpi_plugin_appointmentmanager_blockedperiods` (
            `id`            int(11)      NOT NULL AUTO_INCREMENT,
            `users_id`      int(11)      NOT NULL DEFAULT 0,
            `date_start`    datetime     NOT NULL,
            `date_end`      datetime     NOT NULL,
            `reason`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `date_creation` datetime     NOT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id`   (`users_id`),
            KEY `date_start` (`date_start`),
            KEY `date_end`   (`date_end`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$DB->fieldExists('glpi_plugin_appointmentmanager_appointments', 'followup_id')) {
        $migration->addField(
            'glpi_plugin_appointmentmanager_appointments',
            'followup_id',
            'integer',
            ['value' => 0, 'after' => 'confirm_token']
        );
    }

    if (!$DB->fieldExists('glpi_plugin_appointmentmanager_appointments', 'locations_id')) {
        $migration->addField(
            'glpi_plugin_appointmentmanager_appointments',
            'locations_id',
            'integer',
            ['value' => 0, 'after' => 'date_end']
        );
    }

    if ($DB->fieldExists('glpi_plugin_appointmentmanager_appointments', 'location')) {
        $migration->dropField('glpi_plugin_appointmentmanager_appointments', 'location');
    }

    if (!$DB->tableExists('glpi_plugin_appointmentmanager_oauth_settings')) {
        $migration->addPostQuery("CREATE TABLE `glpi_plugin_appointmentmanager_oauth_settings` (
            `id`            int(11)      NOT NULL AUTO_INCREMENT,
            `provider`      varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `client_id`     text         COLLATE utf8mb4_unicode_ci,
            `client_secret` text         COLLATE utf8mb4_unicode_ci,
            `tenant_id`     varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'common',
            `is_enabled`    tinyint(1)   NOT NULL DEFAULT 0,
            `date_mod`      datetime     NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `provider` (`provider`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$DB->tableExists('glpi_plugin_appointmentmanager_oauth_tokens')) {
        $migration->addPostQuery("CREATE TABLE `glpi_plugin_appointmentmanager_oauth_tokens` (
            `id`            int(11)      NOT NULL AUTO_INCREMENT,
            `users_id`      int(11)      NOT NULL DEFAULT 0,
            `provider`      varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `access_token`  text         COLLATE utf8mb4_unicode_ci,
            `refresh_token` text         COLLATE utf8mb4_unicode_ci,
            `expires_at`    datetime     NOT NULL,
            `date_mod`      datetime     NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_provider` (`users_id`, `provider`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$DB->tableExists('glpi_plugin_appointmentmanager_external_events')) {
        $migration->addPostQuery("CREATE TABLE `glpi_plugin_appointmentmanager_external_events` (
            `id`                int(11)      NOT NULL AUTO_INCREMENT,
            `appointments_id`   int(11)      NOT NULL DEFAULT 0,
            `users_id`          int(11)      NOT NULL DEFAULT 0,
            `provider`          varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `external_event_id` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `date_creation`     datetime     NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `appt_user_provider` (`appointments_id`, `users_id`, `provider`),
            KEY `appointments_id` (`appointments_id`),
            KEY `users_id`        (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $migration->executeMigration();

    PluginAppointmentmanagerAppointmentType::seedDefaults();
    PluginAppointmentmanagerProfile::addDefaultProfileRights();

    return true;
}

function plugin_appointmentmanager_uninstall() {
    PluginAppointmentmanagerProfile::removeRights();
    return true;
}
