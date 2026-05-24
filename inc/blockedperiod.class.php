<?php
class PluginAppointmentmanagerBlockedPeriod {

    public static $rightname = 'plugin_appointmentmanager_availability';

    static function getTypeName($nb = 0) {
        return _n('Blocked Period', 'Blocked Periods', $nb, 'appointmentmanager');
    }

    static function getForUser(int $users_id, bool $future_only = false): array {
        global $DB;

        $where = ['users_id' => $users_id];
        if ($future_only) {
            $where[] = ['date_end' => ['>', date('Y-m-d H:i:s')]];
        }

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_blockedperiods',
            'WHERE' => $where,
            'ORDER' => 'date_start ASC',
        ]);

        $rows = [];
        foreach ($iter as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    static function getForCalendar(int $users_id, string $from, string $to): array {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_blockedperiods',
            'WHERE' => [
                'users_id'   => $users_id,
                'date_start' => ['<', $to],
                'date_end'   => ['>', $from],
            ],
            'ORDER' => 'date_start ASC',
        ]);

        $rows = [];
        foreach ($iter as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    static function create(int $users_id, string $date_start, string $date_end, string $reason): int {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $DB->insert('glpi_plugin_appointmentmanager_blockedperiods', [
            'users_id'      => $users_id,
            'date_start'    => $date_start,
            'date_end'      => $date_end,
            'reason'        => mb_substr(strip_tags(trim($reason)), 0, 255),
            'date_creation' => $now,
        ]);
        return $DB->insertId();
    }

    static function delete(int $id, int $acting_users_id): bool {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_blockedperiods',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ]);
        if ($iter->count() === 0) {
            return false;
        }
        $row = $iter->current();

        $is_admin = Session::haveRight('plugin_appointmentmanager_appointment', UPDATE);
        if ($row['users_id'] !== $acting_users_id && !$is_admin) {
            return false;
        }

        $DB->delete('glpi_plugin_appointmentmanager_blockedperiods', ['id' => $id]);
        return true;
    }

    static function coversDatetime(int $users_id, \DateTime $dt): bool {
        global $DB;

        $ts = $dt->format('Y-m-d H:i:s');
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_blockedperiods',
            'WHERE' => [
                'users_id'   => $users_id,
                'date_start' => ['<=', $ts],
                'date_end'   => ['>', $ts],
            ],
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0;
    }

    static function coversRange(int $users_id, \DateTime $dt_start, \DateTime $dt_end): bool {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_blockedperiods',
            'WHERE' => [
                'users_id'   => $users_id,
                'date_start' => ['<',  $dt_end->format('Y-m-d H:i:s')],
                'date_end'   => ['>', $dt_start->format('Y-m-d H:i:s')],
            ],
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0;
    }
}
