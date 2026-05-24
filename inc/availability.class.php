<?php
class PluginAppointmentmanagerAvailability {

    public static $rightname = 'plugin_appointmentmanager_availability';

    static function getTypeName($nb = 0) {
        return __('Availability', 'appointmentmanager');
    }

    static function getForUser(int $users_id): array {
        global $DB;

        $defaults = self::getDefaultGrid();
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_availability',
            'WHERE' => ['users_id' => $users_id],
            'ORDER' => 'day_of_week ASC',
        ]);

        $saved = [];
        foreach ($iter as $row) {
            $saved[$row['day_of_week']] = $row;
        }

        $result = [];
        foreach ($defaults as $day => $default) {
            $result[$day] = $saved[$day] ?? $default;
        }
        return $result;
    }

    static function saveDay(int $users_id, int $day, string $start, string $end, bool $active): bool {
        global $DB;

        if (!self::validateTime($start) || !self::validateTime($end)) {
            return false;
        }

        $now  = date('Y-m-d H:i:s');
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_availability',
            'WHERE' => ['users_id' => $users_id, 'day_of_week' => $day],
            'LIMIT' => 1,
        ]);

        if ($iter->count() > 0) {
            $DB->update('glpi_plugin_appointmentmanager_availability', [
                'time_start' => $start,
                'time_end'   => $end,
                'is_active'  => $active ? 1 : 0,
                'date_mod'   => $now,
            ], ['users_id' => $users_id, 'day_of_week' => $day]);
        } else {
            $DB->insert('glpi_plugin_appointmentmanager_availability', [
                'users_id'    => $users_id,
                'day_of_week' => $day,
                'time_start'  => $start,
                'time_end'    => $end,
                'is_active'   => $active ? 1 : 0,
                'date_mod'    => $now,
            ]);
        }
        return true;
    }

    static function saveAll(int $users_id, array $days): bool {
        $ok = true;
        foreach ($days as $entry) {
            $day    = (int)($entry['day_of_week'] ?? 0);
            $start  = $entry['time_start'] ?? '08:00:00';
            $end    = $entry['time_end']   ?? '18:00:00';
            $active = !empty($entry['is_active']);
            if ($day >= 1 && $day <= 7) {
                $ok = self::saveDay($users_id, $day, $start, $end, $active) && $ok;
            }
        }
        return $ok;
    }

    static function isAvailable(int $users_id, \DateTime $dt): bool {
        global $DB;

        $dow  = (int)$dt->format('N'); // 1=Mon, 7=Sun
        $time = $dt->format('H:i:s');

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_availability',
            'WHERE' => ['users_id' => $users_id, 'day_of_week' => $dow, 'is_active' => 1],
            'LIMIT' => 1,
        ]);

        if ($iter->count() === 0) {
            return false;
        }
        $row = $iter->current();
        if ($time < $row['time_start'] || $time >= $row['time_end']) {
            return false;
        }

        return !PluginAppointmentmanagerBlockedPeriod::coversDatetime($users_id, $dt);
    }

    static function validateTime(string $t): bool {
        return (bool)preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t);
    }

    static function getDefaultGrid(): array {
        $grid = [];
        $now  = date('Y-m-d H:i:s');
        for ($d = 1; $d <= 7; $d++) {
            $grid[$d] = [
                'users_id'    => 0,
                'day_of_week' => $d,
                'time_start'  => '08:00:00',
                'time_end'    => '18:00:00',
                'is_active'   => ($d <= 5) ? 1 : 0, // Mon-Fri active
                'date_mod'    => $now,
            ];
        }
        return $grid;
    }

    static function getDayNames(): array {
        return [
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
            6 => __('Saturday'),
            7 => __('Sunday'),
        ];
    }
}
