<?php
class PluginAppointmentmanagerCalendarSync {

    // ── Push: lifecycle hooks ─────────────────────────────────────────────────

    static function onAppointmentCreated(array $appt): void {
        self::syncForUser($appt, (int)$appt['users_id_tech'],      'create');
        self::syncForUser($appt, (int)$appt['users_id_requester'], 'create');
    }

    static function onAppointmentUpdated(array $appt): void {
        self::syncForUser($appt, (int)$appt['users_id_tech'],      'update');
        self::syncForUser($appt, (int)$appt['users_id_requester'], 'update');
    }

    static function onStatusChanged(array $appt, string $new_status): void {
        $terminal = [
            PluginAppointmentmanagerAppointment::STATUS_CANCELLED,
            PluginAppointmentmanagerAppointment::STATUS_DECLINED,
        ];
        $action = in_array($new_status, $terminal, true) ? 'delete' : 'update';

        self::syncForUser($appt, (int)$appt['users_id_tech'],      $action);
        self::syncForUser($appt, (int)$appt['users_id_requester'], $action);
    }

    // ── Pull: fetch external busy slots for the mini-calendar ─────────────────

    static function fetchExternalBusySlots(int $users_id, string $from, string $to): array {
        if ($users_id <= 0) {
            return [];
        }

        $busy = [];
        foreach (['google', 'microsoft'] as $provider) {
            try {
                $access_token = PluginAppointmentmanagerOAuthProvider::getValidToken($users_id, $provider);
                if (!$access_token) {
                    continue;
                }
                $instance = PluginAppointmentmanagerOAuthProvider::getProviderInstance($provider);
                if (!$instance) {
                    continue;
                }
                $slots = $instance->fetchEvents($access_token, $from, $to);
                foreach ($slots as $slot) {
                    $busy[] = [
                        'id'      => 'ext_' . $provider . '_' . md5($slot['start'] . $slot['end']),
                        'title'   => __('Busy', 'appointmentmanager'),
                        'start'   => $slot['start'],
                        'end'     => $slot['end'],
                        'display' => 'background',
                        'color'   => '#aaaaaa',
                    ];
                }
            } catch (Throwable $e) {
                error_log('[appointmentmanager] fetchExternalBusySlots(' . $provider . ', user=' . $users_id . '): ' . $e->getMessage());
            }
        }
        return $busy;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private static function syncForUser(array $appt, int $users_id, string $action): void {
        if ($users_id <= 0) {
            return;
        }

        foreach (['google', 'microsoft'] as $provider) {
            try {
                $access_token = PluginAppointmentmanagerOAuthProvider::getValidToken($users_id, $provider);
                if (!$access_token) {
                    continue;
                }

                $instance  = PluginAppointmentmanagerOAuthProvider::getProviderInstance($provider);
                $event_id  = self::getExternalEventId((int)$appt['id'], $users_id, $provider);
                $event_data = self::buildEventData($appt, $provider);

                if ($action === 'create' || ($action === 'update' && !$event_id)) {
                    $new_id = $instance->createEvent($access_token, $event_data);
                    self::storeExternalEventId((int)$appt['id'], $users_id, $provider, $new_id);
                } elseif ($action === 'update' && $event_id) {
                    $instance->updateEvent($access_token, $event_id, $event_data);
                } elseif ($action === 'delete' && $event_id) {
                    $instance->deleteEvent($access_token, $event_id);
                    self::deleteExternalEventId((int)$appt['id'], $users_id, $provider);
                }
            } catch (Throwable $e) {
                error_log('[appointmentmanager] CalendarSync::syncForUser(action=' . $action . ', provider=' . $provider . ', user=' . $users_id . '): ' . $e->getMessage());
            }
        }
    }

    private static function buildEventData(array $appt, string $provider): array {
        $type_name = '';
        if (!empty($appt['appointmenttypes_id'])) {
            $type = PluginAppointmentmanagerAppointmentType::getById((int)$appt['appointmenttypes_id']);
            $type_name = $type['name'] ?? '';
        }

        $statuses = PluginAppointmentmanagerAppointment::getAllStatuses();
        $status   = $statuses[$appt['status'] ?? ''] ?? ($appt['status'] ?? '');

        $title = '[' . $status . '] ' . __('Appointment', 'appointmentmanager');
        if ($type_name) {
            $title .= ' – ' . $type_name;
        }
        $title .= ' – ' . __('Ticket', 'appointmentmanager') . ' #' . (int)$appt['tickets_id'];

        $tz  = date_default_timezone_get() ?: 'UTC';
        $description = __('Ticket', 'appointmentmanager') . ' #' . (int)$appt['tickets_id'];
        if (!empty($appt['comment'])) {
            $description .= "\n" . strip_tags($appt['comment']);
        }

        if ($provider === 'google') {
            $data = [
                'summary'     => $title,
                'description' => $description,
                'start'       => ['dateTime' => date('c', strtotime($appt['date_start'])), 'timeZone' => $tz],
                'end'         => ['dateTime' => date('c', strtotime($appt['date_end'])),   'timeZone' => $tz],
            ];
            if (!empty($appt['location'])) {
                $data['location'] = $appt['location'];
            }
            return $data;
        }

        // Microsoft
        $data = [
            'subject'  => $title,
            'body'     => ['contentType' => 'text', 'content' => $description],
            'start'    => ['dateTime' => date('Y-m-d\TH:i:s', strtotime($appt['date_start'])), 'timeZone' => $tz],
            'end'      => ['dateTime' => date('Y-m-d\TH:i:s', strtotime($appt['date_end'])),   'timeZone' => $tz],
        ];
        if (!empty($appt['location'])) {
            $data['location'] = ['displayName' => $appt['location']];
        }
        return $data;
    }

    // ── External event ID storage ─────────────────────────────────────────────

    private static function getExternalEventId(int $appointments_id, int $users_id, string $provider): ?string {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_external_events',
            'WHERE' => [
                'appointments_id' => $appointments_id,
                'users_id'        => $users_id,
                'provider'        => $provider,
            ],
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0 ? $iter->current()['external_event_id'] : null;
    }

    private static function storeExternalEventId(int $appointments_id, int $users_id, string $provider, string $event_id): void {
        global $DB;

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_external_events',
            'WHERE' => ['appointments_id' => $appointments_id, 'users_id' => $users_id, 'provider' => $provider],
            'LIMIT' => 1,
        ]);

        if ($existing->count() > 0) {
            $DB->update('glpi_plugin_appointmentmanager_external_events',
                ['external_event_id' => $event_id],
                ['appointments_id' => $appointments_id, 'users_id' => $users_id, 'provider' => $provider]
            );
        } else {
            $DB->insert('glpi_plugin_appointmentmanager_external_events', [
                'appointments_id'   => $appointments_id,
                'users_id'          => $users_id,
                'provider'          => $provider,
                'external_event_id' => $event_id,
                'date_creation'     => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private static function deleteExternalEventId(int $appointments_id, int $users_id, string $provider): void {
        global $DB;
        $DB->delete('glpi_plugin_appointmentmanager_external_events', [
            'appointments_id' => $appointments_id,
            'users_id'        => $users_id,
            'provider'        => $provider,
        ]);
    }
}
