<?php
class PluginAppointmentmanagerAppointment extends CommonDBTM {

    public static $rightname = 'plugin_appointmentmanager_appointment';

    const STATUS_PROPOSED             = 'proposed';
    const STATUS_CONFIRMED            = 'confirmed';
    const STATUS_DECLINED             = 'declined';
    const STATUS_RESCHEDULE_REQUESTED = 'reschedule_requested';
    const STATUS_CANCELLED            = 'cancelled';
    const STATUS_COMPLETED            = 'completed';

    static function getTypeName($nb = 0) {
        return _n('Appointment', 'Appointments', $nb, 'appointmentmanager');
    }

    static function getAllStatuses(): array {
        return [
            self::STATUS_PROPOSED             => __('Proposed', 'appointmentmanager'),
            self::STATUS_CONFIRMED            => __('Confirmed', 'appointmentmanager'),
            self::STATUS_DECLINED             => __('Declined', 'appointmentmanager'),
            self::STATUS_RESCHEDULE_REQUESTED => __('Reschedule requested', 'appointmentmanager'),
            self::STATUS_CANCELLED            => __('Cancelled', 'appointmentmanager'),
            self::STATUS_COMPLETED            => __('Completed', 'appointmentmanager'),
        ];
    }

    static function getStatusBadgeClass(string $status): string {
        $map = [
            self::STATUS_PROPOSED             => 'bg-warning text-dark',
            self::STATUS_CONFIRMED            => 'bg-success',
            self::STATUS_DECLINED             => 'bg-danger',
            self::STATUS_RESCHEDULE_REQUESTED => 'bg-info text-dark',
            self::STATUS_CANCELLED            => 'bg-secondary',
            self::STATUS_COMPLETED            => 'bg-primary',
        ];
        return $map[$status] ?? 'bg-secondary';
    }

    static function generateToken(): string {
        return bin2hex(random_bytes(32));
    }

    static function getByToken(string $token): ?array {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_appointments',
            'WHERE' => ['confirm_token' => $token],
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0 ? $iter->current() : null;
    }

    static function getById(?int $id): ?array {
        global $DB;

        if (!$id) {
            return null;
        }

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_appointments',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0 ? $iter->current() : null;
    }

    static function getForTicket(int $tickets_id): array {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_appointments',
            'WHERE' => ['tickets_id' => $tickets_id],
            'ORDER' => 'date_creation DESC',
        ]);

        $rows = [];
        foreach ($iter as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    static function getActiveForTicket(int $tickets_id): ?array {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_appointments',
            'WHERE' => [
                'tickets_id' => $tickets_id,
                'status'     => [
                    self::STATUS_PROPOSED,
                    self::STATUS_CONFIRMED,
                    self::STATUS_RESCHEDULE_REQUESTED,
                ],
            ],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0 ? $iter->current() : null;
    }

    static function getForTech(int $users_id, string $from, string $to): array {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_appointments',
            'WHERE' => [
                'users_id_tech' => $users_id,
                'date_start'    => ['<', $to],
                'date_end'      => ['>', $from],
            ],
            'ORDER' => 'date_start ASC',
        ]);

        $rows = [];
        foreach ($iter as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    static function hasTechConflict(int $tech_id, \DateTime $dt_start, \DateTime $dt_end, int $exclude_id = 0): bool {
        global $DB;

        $where = [
            'users_id_tech' => $tech_id,
            'date_start'    => ['<', $dt_end->format('Y-m-d H:i:s')],
            'date_end'      => ['>', $dt_start->format('Y-m-d H:i:s')],
            'status'        => [self::STATUS_PROPOSED, self::STATUS_CONFIRMED],
        ];
        if ($exclude_id > 0) {
            $where['NOT'] = ['id' => $exclude_id];
        }

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_appointments',
            'WHERE' => $where,
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0;
    }

    static function getAll(string $from, string $to): array {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_appointments',
            'WHERE' => [
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

    static function create(array $input): int {
        global $DB, $CFG_GLPI;

        $tickets_id          = (int)($input['tickets_id'] ?? 0);
        $users_id_tech       = (int)($input['users_id_tech'] ?? 0);
        $users_id_requester  = (int)($input['users_id_requester'] ?? 0);
        $appointmenttypes_id = (int)($input['appointmenttypes_id'] ?? 0);
        $date_start          = $input['date_start'] ?? '';
        $date_end            = $input['date_end'] ?? '';
        $locations_id        = (int)($input['locations_id'] ?? 0);
        $comment               = strip_tags(trim($input['comment'] ?? ''));
        $is_requester_proposed = (int)($input['is_requester_proposed'] ?? 0);
        $token                 = self::generateToken();
        $now                   = date('Y-m-d H:i:s');

        $DB->insert('glpi_plugin_appointmentmanager_appointments', [
            'tickets_id'           => $tickets_id,
            'users_id_tech'        => $users_id_tech,
            'users_id_requester'   => $users_id_requester,
            'appointmenttypes_id'  => $appointmenttypes_id,
            'status'               => self::STATUS_PROPOSED,
            'date_start'           => $date_start,
            'date_end'             => $date_end,
            'locations_id'         => $locations_id,
            'comment'              => $comment,
            'confirm_token'        => $token,
            'is_requester_proposed'=> $is_requester_proposed,
            'date_creation'        => $now,
            'date_mod'             => $now,
        ]);

        $id = $DB->insertId();

        self::postFollowupForCreation($id, $tickets_id, $token, $input);

        try {
            PluginAppointmentmanagerCalendarSync::onAppointmentCreated(self::getById($id));
        } catch (Throwable $e) {
            error_log('[appointmentmanager] CalendarSync::onAppointmentCreated failed: ' . $e->getMessage());
        }

        return $id;
    }

    static function updateStatus(int $id, string $status, int $acting_users_id): bool {
        global $DB;

        $appt = self::getById($id);
        if (!$appt) {
            return false;
        }
        if (!self::isValidTransition($appt['status'], $status)) {
            return false;
        }

        $DB->update('glpi_plugin_appointmentmanager_appointments', [
            'status'   => $status,
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        try {
            PluginAppointmentmanagerCalendarSync::onStatusChanged(self::getById($id), $status);
        } catch (Throwable $e) {
            error_log('[appointmentmanager] CalendarSync::onStatusChanged failed: ' . $e->getMessage());
        }

        return true;
    }

    static function isValidTransition(string $from, string $to): bool {
        $allowed = [
            self::STATUS_PROPOSED  => [self::STATUS_CONFIRMED, self::STATUS_DECLINED, self::STATUS_RESCHEDULE_REQUESTED, self::STATUS_CANCELLED],
            self::STATUS_CONFIRMED => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_RESCHEDULE_REQUESTED => [self::STATUS_CANCELLED],
        ];
        return in_array($to, $allowed[$from] ?? [], true);
    }

    static function userCanActOnToken(int $appointment_id, int $users_id): bool {
        $appt = self::getById($appointment_id);
        if (!$appt) {
            return false;
        }
        return $appt['users_id_requester'] === $users_id;
    }

    static function techOwns(int $appointment_id, int $users_id): bool {
        $appt = self::getById($appointment_id);
        if (!$appt) {
            return false;
        }
        return $appt['users_id_tech'] === $users_id;
    }

    static function isEnrolled(int $users_id): bool {
        global $DB;
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_appointmentmanager_enrolled',
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
        ]);
        return count($iter) > 0;
    }

    static function postFollowupForCreation(int $appt_id, int $tickets_id, string $token, array $input): void {
        global $CFG_GLPI, $DB;

        // Use a root-relative URL so the browser resolves the hostname from
        // its current context. This avoids url_base / localhost misconfigurations.
        $root       = rtrim($CFG_GLPI['root_doc'] ?? '', '/');
        $action_url = $root . '/plugins/appointmentmanager/front/action.php';
        $confirm_url    = htmlspecialchars($action_url . '?token=' . urlencode($token) . '&action=confirm',    ENT_QUOTES, 'UTF-8');
        $decline_url    = htmlspecialchars($action_url . '?token=' . urlencode($token) . '&action=decline',    ENT_QUOTES, 'UTF-8');
        $reschedule_url = htmlspecialchars($action_url . '?token=' . urlencode($token) . '&action=reschedule', ENT_QUOTES, 'UTF-8');

        $type_name = '';
        if (!empty($input['appointmenttypes_id'])) {
            $type = PluginAppointmentmanagerAppointmentType::getById((int)$input['appointmenttypes_id']);
            $type_name = $type ? htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') : '';
        }

        $date_start_fmt = Html::convDateTime($input['date_start'] ?? '');
        $date_end_fmt   = Html::convDateTime($input['date_end'] ?? '');
        $locations_id   = (int)($input['locations_id'] ?? 0);
        $location_name  = $locations_id > 0 ? htmlspecialchars(Dropdown::getDropdownName('glpi_locations', $locations_id), ENT_QUOTES, 'UTF-8') : '';
        $comment        = htmlspecialchars($input['comment'] ?? '', ENT_QUOTES, 'UTF-8');

        $is_req = !empty($input['is_requester_proposed']);
        $intro  = $is_req
            ? __('The requester has proposed a new appointment time. The assigned technician must confirm or decline.', 'appointmentmanager')
            : __('A new appointment has been proposed for this ticket.', 'appointmentmanager');

        $content  = '<p>' . $intro . '</p>';
        if ($type_name) {
            $content .= '<p><strong>' . __('Type', 'appointmentmanager') . ':</strong> ' . $type_name . '</p>';
        }
        $content .= '<p><strong>' . __('Start', 'appointmentmanager') . ':</strong> ' . $date_start_fmt . '</p>';
        $content .= '<p><strong>' . __('End', 'appointmentmanager') . ':</strong> ' . $date_end_fmt . '</p>';
        if ($location_name) {
            $content .= '<p><strong>' . __('Location', 'appointmentmanager') . ':</strong> ' . $location_name . '</p>';
        }
        if ($comment) {
            $content .= '<p><strong>' . __('Comment', 'appointmentmanager') . ':</strong> ' . $comment . '</p>';
        }
        $content .= '<p>';
        $content .= '<a class="btn btn-sm btn-success me-2" href="' . $confirm_url . '">' . __('Confirm', 'appointmentmanager') . '</a> ';
        $content .= '<a class="btn btn-sm btn-danger me-2" href="' . $decline_url . '">' . __('Decline', 'appointmentmanager') . '</a> ';
        $content .= '<a class="btn btn-sm btn-secondary" href="' . $reschedule_url . '">' . __('Request reschedule', 'appointmentmanager') . '</a>';
        $content .= '</p>';

        $followup = new ITILFollowup();
        $fu_id = $followup->add([
            'itemtype'        => 'Ticket',
            'items_id'        => $tickets_id,
            'users_id'        => Session::getLoginUserID(),
            'content'         => $content,
            'is_private'      => 0,
            'requesttypes_id' => 0,
        ]);

        if ($fu_id && $appt_id) {
            $DB->update('glpi_plugin_appointmentmanager_appointments',
                ['followup_id' => (int)$fu_id],
                ['id' => $appt_id]
            );
        }
    }

    static function replaceProposalButtons(int $appt_id, string $new_status, int $acting_users_id): void {
        global $DB;

        $appt = self::getById($appt_id);
        if (!$appt || empty($appt['followup_id'])) {
            return;
        }

        $fu_id = (int)$appt['followup_id'];

        $type_name = '';
        if (!empty($appt['appointmenttypes_id'])) {
            $type = PluginAppointmentmanagerAppointmentType::getById((int)$appt['appointmenttypes_id']);
            $type_name = $type ? htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') : '';
        }

        $date_start_fmt = Html::convDateTime($appt['date_start']);
        $date_end_fmt   = Html::convDateTime($appt['date_end']);
        $locations_id   = (int)($appt['locations_id'] ?? 0);
        $location_name  = $locations_id > 0 ? htmlspecialchars(Dropdown::getDropdownName('glpi_locations', $locations_id), ENT_QUOTES, 'UTF-8') : '';

        $content  = '<p>' . __('A new appointment has been proposed for this ticket.', 'appointmentmanager') . '</p>';
        if ($type_name) {
            $content .= '<p><strong>' . __('Type', 'appointmentmanager') . ':</strong> ' . $type_name . '</p>';
        }
        $content .= '<p><strong>' . __('Start', 'appointmentmanager') . ':</strong> ' . $date_start_fmt . '</p>';
        $content .= '<p><strong>' . __('End', 'appointmentmanager') . ':</strong> ' . $date_end_fmt . '</p>';
        if ($location_name) {
            $content .= '<p><strong>' . __('Location', 'appointmentmanager') . ':</strong> ' . $location_name . '</p>';
        }
        $comment = htmlspecialchars($appt['comment'] ?? '', ENT_QUOTES, 'UTF-8');
        if ($comment) {
            $content .= '<p><strong>' . __('Comment', 'appointmentmanager') . ':</strong> ' . $comment . '</p>';
        }

        $badge_class = self::getStatusBadgeClass($new_status);
        $statuses    = self::getAllStatuses();
        $label       = htmlspecialchars($statuses[$new_status] ?? $new_status, ENT_QUOTES, 'UTF-8');
        $user_name   = htmlspecialchars(User::getFriendlyNameById($acting_users_id), ENT_QUOTES, 'UTF-8');
        $content .= '<p><span class="badge ' . $badge_class . '">' . $label . '</span> '
                  . sprintf(__('by %s', 'appointmentmanager'), $user_name) . '</p>';

        $DB->update('glpi_itilfollowups', ['content' => $content], ['id' => $fu_id]);
    }

    static function updateDetails(int $id, array $input, int $acting_users_id): bool {
        global $DB;

        $appt = self::getById($id);
        if (!$appt) {
            return false;
        }

        $DB->update('glpi_plugin_appointmentmanager_appointments', [
            'appointmenttypes_id'  => (int)($input['appointmenttypes_id'] ?? $appt['appointmenttypes_id']),
            'date_start'           => $input['date_start'],
            'date_end'             => $input['date_end'],
            'locations_id'         => (int)($input['locations_id'] ?? $appt['locations_id'] ?? 0),
            'status'               => self::STATUS_PROPOSED,
            'is_requester_proposed'=> 0,
            'date_mod'             => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        self::refreshProposalFollowup($id);

        try {
            PluginAppointmentmanagerCalendarSync::onAppointmentUpdated(self::getById($id));
        } catch (Throwable $e) {
            error_log('[appointmentmanager] CalendarSync::onAppointmentUpdated failed: ' . $e->getMessage());
        }

        return true;
    }

    static function refreshProposalFollowup(int $appt_id): void {
        global $DB, $CFG_GLPI;

        $appt = self::getById($appt_id);
        if (!$appt || empty($appt['followup_id'])) {
            return;
        }

        $root           = rtrim($CFG_GLPI['root_doc'] ?? '', '/');
        $action_url     = $root . '/plugins/appointmentmanager/front/action.php';
        $token          = $appt['confirm_token'];
        $confirm_url    = htmlspecialchars($action_url . '?token=' . urlencode($token) . '&action=confirm',    ENT_QUOTES, 'UTF-8');
        $decline_url    = htmlspecialchars($action_url . '?token=' . urlencode($token) . '&action=decline',    ENT_QUOTES, 'UTF-8');
        $reschedule_url = htmlspecialchars($action_url . '?token=' . urlencode($token) . '&action=reschedule', ENT_QUOTES, 'UTF-8');

        $type_name = '';
        if (!empty($appt['appointmenttypes_id'])) {
            $type = PluginAppointmentmanagerAppointmentType::getById((int)$appt['appointmenttypes_id']);
            $type_name = $type ? htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') : '';
        }
        $date_start_fmt = Html::convDateTime($appt['date_start']);
        $date_end_fmt   = Html::convDateTime($appt['date_end']);
        $locations_id   = (int)($appt['locations_id'] ?? 0);
        $location_name  = $locations_id > 0 ? htmlspecialchars(Dropdown::getDropdownName('glpi_locations', $locations_id), ENT_QUOTES, 'UTF-8') : '';

        $is_req = !empty($appt['is_requester_proposed']);
        $intro  = $is_req
            ? __('The requester has proposed a new appointment time. The assigned technician must confirm or decline.', 'appointmentmanager')
            : __('A new appointment has been proposed for this ticket.', 'appointmentmanager');

        $content  = '<p>' . $intro . '</p>';
        if ($type_name) {
            $content .= '<p><strong>' . __('Type', 'appointmentmanager') . ':</strong> ' . $type_name . '</p>';
        }
        $content .= '<p><strong>' . __('Start', 'appointmentmanager') . ':</strong> ' . $date_start_fmt . '</p>';
        $content .= '<p><strong>' . __('End', 'appointmentmanager') . ':</strong> ' . $date_end_fmt . '</p>';
        if ($location_name) {
            $content .= '<p><strong>' . __('Location', 'appointmentmanager') . ':</strong> ' . $location_name . '</p>';
        }
        $content .= '<p>';
        $content .= '<a class="btn btn-sm btn-success me-2" href="' . $confirm_url . '">' . __('Confirm', 'appointmentmanager') . '</a> ';
        $content .= '<a class="btn btn-sm btn-danger me-2" href="' . $decline_url . '">' . __('Decline', 'appointmentmanager') . '</a> ';
        $content .= '<a class="btn btn-sm btn-secondary" href="' . $reschedule_url . '">' . __('Request reschedule', 'appointmentmanager') . '</a>';
        $content .= '</p>';

        $DB->update('glpi_itilfollowups', ['content' => $content], ['id' => (int)$appt['followup_id']]);
    }
    // ── Timeline action bar button + modal ───────────────────────────────────

    static function showTimelineButton(Ticket $ticket, int $rand): void {
        $tickets_id   = $ticket->getID();
        $plugin_url   = Plugin::getWebDir('appointmentmanager', true);
        $csrf         = Session::getNewCSRFToken();
        $types        = PluginAppointmentmanagerAppointmentType::getAll(true);
        $modal_id     = 'amProposeModal' . $rand;
        $cal_id       = 'amCalendar' . $rand;
        $start_id     = 'amStart' . $rand;
        $end_id       = 'amEnd' . $rand;
        $current_user = (int)Session::getLoginUserID();
        $events_url   = $plugin_url . '/ajax/events.php';

        $active_appt     = self::getActiveForTicket($tickets_id);
        $other_tech_appt = null;
        $is_update       = false;

        if ($active_appt !== null) {
            if ((int)$active_appt['users_id_tech'] === $current_user) {
                $is_update = true;
            } else {
                $other_tech_appt = $active_appt;
            }
        }

        $modal_title  = $is_update ? __('Update appointment', 'appointmentmanager') : __('Propose appointment', 'appointmentmanager');
        $btn_label    = __('Appointment', 'appointmentmanager');
        $btn_icon     = $is_update ? 'ti-calendar-event' : 'ti-calendar-plus';
        $submit_label = $is_update ? __('Update', 'appointmentmanager') : __('Propose', 'appointmentmanager');

        // Load FullCalendar CSS + JS once per page render
        static $fc_loaded = false;
        if (!$fc_loaded) {
            echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">';
            echo '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>';
            $fc_loaded = true;
        }

        $btn_id = 'amProposeBtn' . $rand;
        echo '<button type="button"'
            . ' id="' . $btn_id . '"'
            . ' class="btn btn-primary ms-2"'
            . ' style="background-color:#80c9c9;border-color:#80c9c9;color:#1a4a4a;"'
            . ' data-bs-toggle="modal"'
            . ' data-bs-target="#' . $modal_id . '"'
            . ' title="' . htmlspecialchars($btn_label, ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="ti ' . $btn_icon . '"></i>'
            . '<span class="d-none d-lg-inline ms-1">' . $btn_label . '</span>'
            . '</button>';

        echo '<div class="modal fade" id="' . $modal_id . '" tabindex="-1" aria-hidden="true">';
        echo '<div class="modal-dialog modal-xl"><div class="modal-content">';
        echo '<form method="POST" action="' . htmlspecialchars($plugin_url . '/front/appointment.form.php', ENT_QUOTES, 'UTF-8') . '">';
        echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
        echo Html::hidden('tickets_id', ['value' => $tickets_id]);
        echo Html::hidden('appointment_id', ['value' => $is_update ? (int)$active_appt['id'] : 0]);

        echo '<div class="modal-header">';
        echo '<h5 class="modal-title">' . $modal_title . '</h5>';
        echo '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>';
        echo '</div>';

        echo '<div class="modal-body"><div class="row g-3">';

        if ($other_tech_appt !== null) {
            $other_tech_name    = htmlspecialchars(User::getFriendlyNameById((int)$other_tech_appt['users_id_tech']), ENT_QUOTES, 'UTF-8');
            $other_date_start   = Html::convDateTime($other_tech_appt['date_start']);
            $other_date_end     = Html::convDateTime($other_tech_appt['date_end']);
            $all_statuses       = self::getAllStatuses();
            $other_status_label = htmlspecialchars($all_statuses[$other_tech_appt['status']] ?? $other_tech_appt['status'], ENT_QUOTES, 'UTF-8');
            $badge_class        = self::getStatusBadgeClass($other_tech_appt['status']);

            echo '<div class="col-12">';
            echo '<div class="alert alert-warning d-flex gap-2 align-items-start">';
            echo '<i class="ti ti-alert-triangle fs-4 flex-shrink-0"></i>';
            echo '<div>';
            echo '<strong>' . __('Existing appointment on this ticket', 'appointmentmanager') . '</strong><br>';
            echo sprintf(__('Tech: %s', 'appointmentmanager'), $other_tech_name);
            echo ' &nbsp;<span class="badge ' . $badge_class . '">' . $other_status_label . '</span><br>';
            echo sprintf(__('From %s to %s', 'appointmentmanager'), $other_date_start, $other_date_end);
            echo '<br><small class="text-muted">'
                . __('You can still propose a new appointment, but coordinate with the other technician first.', 'appointmentmanager')
                . '</small>';
            echo '</div></div></div>';
        }

        // ── Left: mini calendar ───────────────────────────────────────────────
        echo '<div class="col-lg-7">';
        echo '<p class="text-muted small mb-1">' . __('Click or drag a slot to set the appointment time.', 'appointmentmanager') . '</p>';
        echo '<div id="' . $cal_id . '"></div>';
        echo '</div>';

        // ── Right: form fields ────────────────────────────────────────────────
        echo '<div class="col-lg-5">';

        echo '<div class="mb-3"><label class="form-label">' . __('Appointment type', 'appointmentmanager') . ' *</label>';
        echo '<select name="appointmenttypes_id" class="form-select" required>';
        echo '<option value="">' . __('Select a type…', 'appointmentmanager') . '</option>';
        foreach ($types as $type) {
            $selected = ($is_update && (int)$type['id'] === (int)$active_appt['appointmenttypes_id']) ? ' selected' : '';
            echo '<option value="' . (int)$type['id'] . '"' . $selected . '>'
                . htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select></div>';

        echo '<div class="mb-3"><label class="form-label">' . __('Start') . ' *</label>';
        echo '<input type="datetime-local" id="' . $start_id . '" name="date_start" class="form-control" required></div>';

        echo '<div class="mb-3"><label class="form-label">' . __('End') . ' *</label>';
        echo '<input type="datetime-local" id="' . $end_id . '" name="date_end" class="form-control" required></div>';

        echo '<div class="mb-3"><label class="form-label">' . __('Location') . '</label>';
        Location::dropdown([
            'name'   => 'locations_id',
            'value'  => $is_update ? (int)$active_appt['locations_id'] : 0,
            'entity' => $_SESSION['glpiactive_entity'] ?? 0,
        ]);
        echo '</div>';

        if (!$is_update) {
            echo '<div class="mb-3"><label class="form-label">' . __('Comment') . '</label>';
            echo '<textarea name="comment" class="form-control" rows="3"></textarea></div>';
        }

        echo '</div>'; // col-lg-5

        echo '</div></div>'; // row, modal-body

        echo '<div class="modal-footer">';
        echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . __('Cancel') . '</button>';
        echo '<button type="submit" class="btn btn-primary">'
            . '<i class="ti ti-send me-1"></i>' . $submit_label . '</button>';
        echo '</div>';

        echo '</form></div></div></div>';

        // Move the modal to <body> so Bootstrap's backdrop z-index works correctly.
        // When rendered inside GLPI's ticket <form>, the modal backdrop blocks interaction.
        echo '<script>(function(){'
            . 'var m=document.getElementById("' . $modal_id . '");if(m)document.body.appendChild(m);'
            . 'var b=document.getElementById("' . $btn_id . '");'
            . 'var ma=document.querySelector("#itil-footer .main-actions");'
            . 'if(b&&ma){ma.appendChild(b);}'
            . '})();</script>';

        // ── Mini-calendar initialization ──────────────────────────────────────
        $j_cal_id     = json_encode($cal_id,     JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $j_modal_id   = json_encode($modal_id,   JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $j_start_id   = json_encode($start_id,   JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $j_end_id     = json_encode($end_id,     JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $j_events_url = json_encode($events_url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $j_tech_id    = json_encode($current_user);

        echo '<script>
(function() {
    var modalEl  = document.getElementById(' . $j_modal_id . ');
    var calEl    = document.getElementById(' . $j_cal_id . ');
    var startEl  = document.getElementById(' . $j_start_id . ');
    var endEl    = document.getElementById(' . $j_end_id . ');
    var eventsUrl = ' . $j_events_url . ';
    var techId    = ' . $j_tech_id . ';
    var calendar  = null;

    function toLocalInput(d) {
        var pad = function(n) { return n < 10 ? "0" + n : "" + n; };
        return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate())
             + "T" + pad(d.getHours()) + ":" + pad(d.getMinutes());
    }

    var calOptions = {
        initialView: "timeGridWeek",
        firstDay: 1,
        height: 450,
        slotMinTime: "06:00:00",
        slotMaxTime: "21:00:00",
        nowIndicator: true,
        headerToolbar: { left: "prev,next today", center: "title", right: "" },
        selectable: true,
        selectMirror: true,
        select: function(info) {
            if (startEl) startEl.value = toLocalInput(info.start);
            if (endEl)   endEl.value   = toLocalInput(info.end);
        },
        selectAllow: function(info) {
            var events = calendar.getEvents();
            for (var i = 0; i < events.length; i++) {
                var ev = events[i];
                if (ev.start < info.end && ev.end > info.start) {
                    return false;
                }
            }
            return true;
        },
        events: {
            url: eventsUrl,
            method: "GET",
            extraParams: function() { return { techs_id: techId }; },
            failure: function() { console.error("[appointmentmanager] Failed to load events"); }
        },
        eventContent: function(arg) {
            if (arg.event.display === "background") return;
            var icons = { proposed: "⏳", confirmed: "✅", declined: "❌",
                          reschedule_requested: "🔄", cancelled: "🚫", completed: "🏁" };
            var icon = icons[arg.event.extendedProps.status || ""] || "";
            return { html: "<div style=\"white-space:normal;font-size:0.8em;padding:1px 3px;overflow:hidden\">" + icon + " " + arg.event.title + "</div>" };
        },
        eventDidMount: function(arg) {
            if (arg.event.display === "background") {
                var label = document.createElement("span");
                label.textContent = arg.event.title || "Busy";
                label.style.cssText = "font-size:0.7em;font-weight:600;color:rgba(0,0,0,0.45);padding:1px 4px;display:block;text-align:center;pointer-events:none;";
                arg.el.appendChild(label);
            } else {
                arg.el.style.overflow = "hidden";
            }
        }
    };

    if (modalEl) {
        modalEl.addEventListener("shown.bs.modal", function() {
            if (!calendar) {
                calendar = new FullCalendar.Calendar(calEl, calOptions);
                calendar.render();
            } else {
                calendar.updateSize();
                calendar.refetchEvents();
            }
        });
    }
})();
</script>';

        if ($is_update) {
            $js_start = json_encode(substr($active_appt['date_start'], 0, 16));
            $js_end   = json_encode(substr($active_appt['date_end'],   0, 16));
            echo '<script>
(function() {
    var modalEl = document.getElementById(' . $j_modal_id . ');
    if (!modalEl) return;
    modalEl.addEventListener("shown.bs.modal", function() {
        var s = document.getElementById(' . $j_start_id . ');
        var e = document.getElementById(' . $j_end_id . ');
        if (s && !s.value) s.value = ' . $js_start . ';
        if (e && !e.value) e.value = ' . $js_end . ';
    });
})();
</script>';
        }
    }

}
