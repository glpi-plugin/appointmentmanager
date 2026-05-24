<?php
class PluginAppointmentmanagerAppointmentType {

    public static $rightname = 'plugin_appointmentmanager_type';

    static function getTypeName($nb = 0) {
        return _n('Appointment Type', 'Appointment Types', $nb, 'appointmentmanager');
    }

    static function getAll(bool $active_only = true): array {
        global $DB;

        $where = $active_only ? ['is_active' => 1] : [];
        $iter  = $DB->request([
            'FROM'    => 'glpi_plugin_appointmentmanager_appointmenttypes',
            'WHERE'   => $where,
            'ORDER'   => ['sort_order ASC', 'name ASC'],
        ]);

        $rows = [];
        foreach ($iter as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    static function getById(int $id): ?array {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_appointmenttypes',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0 ? $iter->current() : null;
    }

    static function create(array $data): int {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $DB->insert('glpi_plugin_appointmentmanager_appointmenttypes', [
            'name'          => self::sanitizeName($data['name'] ?? ''),
            'color'         => self::validateColor($data['color'] ?? '') ? $data['color'] : '#0055a4',
            'icon'          => self::validateIconClass($data['icon'] ?? '') ? trim($data['icon']) : '',
            'is_active'     => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
            'is_default'    => 0,
            'sort_order'    => (int)($data['sort_order'] ?? 0),
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);
        return $DB->insertId();
    }

    static function update(int $id, array $data): bool {
        global $DB;

        $row = self::getById($id);
        if (!$row) {
            return false;
        }

        $updates = [
            'name'       => self::sanitizeName($data['name'] ?? $row['name']),
            'color'      => self::validateColor($data['color'] ?? '') ? $data['color'] : $row['color'],
            'icon'       => self::validateIconClass($data['icon'] ?? '') ? trim($data['icon']) : $row['icon'],
            'is_active'  => isset($data['is_active']) ? (int)(bool)$data['is_active'] : $row['is_active'],
            'sort_order' => isset($data['sort_order']) ? (int)$data['sort_order'] : $row['sort_order'],
            'date_mod'   => date('Y-m-d H:i:s'),
        ];
        return $DB->update('glpi_plugin_appointmentmanager_appointmenttypes', $updates, ['id' => $id]);
    }

    static function delete(int $id): bool {
        global $DB;

        $row = self::getById($id);
        if (!$row) {
            return false;
        }
        if ($row['is_default']) {
            return false;
        }

        $DB->update('glpi_plugin_appointmentmanager_appointmenttypes', [
            'is_active' => 0,
            'date_mod'  => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
        return true;
    }

    static function seedDefaults(): void {
        global $DB;

        $existing = $DB->request(['FROM' => 'glpi_plugin_appointmentmanager_appointmenttypes']);
        if ($existing->count() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $defaults = [
            ['name' => 'On-site visit',       'color' => '#e74c3c', 'icon' => 'ti ti-map-pin',      'sort_order' => 1],
            ['name' => 'Remote control',       'color' => '#3498db', 'icon' => 'ti ti-screen-share', 'sort_order' => 2],
            ['name' => 'Phone / Video call',   'color' => '#2ecc71', 'icon' => 'ti ti-phone',        'sort_order' => 3],
        ];

        foreach ($defaults as $row) {
            $DB->insert('glpi_plugin_appointmentmanager_appointmenttypes', [
                'name'          => $row['name'],
                'color'         => $row['color'],
                'icon'          => $row['icon'],
                'is_active'     => 1,
                'is_default'    => 1,
                'sort_order'    => $row['sort_order'],
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
        }
    }

    static function validateColor(string $color): bool {
        return (bool)preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color);
    }

    static function validateIconClass(string $icon): bool {
        if (empty($icon)) {
            return true;
        }
        return (bool)preg_match('/^[a-zA-Z0-9 _-]+$/', $icon);
    }

    static function sanitizeName(string $val): string {
        return mb_substr(strip_tags(trim($val)), 0, 255);
    }
}
