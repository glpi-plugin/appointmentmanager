<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

$from_raw = trim($_GET['start'] ?? '');
$to_raw   = trim($_GET['end']   ?? '');
$techs_id = (int)($_GET['techs_id'] ?? 0);

$has_read = Session::haveRight('plugin_appointmentmanager_appointment', READ);
if (!$has_read && $techs_id <= 0) {
    echo json_encode(['error' => 'Permission denied'], JSON_HEX_TAG | JSON_HEX_AMP);
    http_response_code(403);
    exit;
}

if (!$from_raw || !$to_raw) {
    echo json_encode(['error' => 'Missing start/end'], JSON_HEX_TAG | JSON_HEX_AMP);
    http_response_code(400);
    exit;
}

// FullCalendar sends ISO 8601 with local timezone offset (e.g. 2026-06-15T00:00:00+02:00).
// MySQL DATETIME comparison is unreliable with timezone suffixes, so strip to plain datetime.
// Keep the originals for external calendar API calls which accept RFC 3339 directly.
$from = str_replace('T', ' ', substr($from_raw, 0, 19));
$to   = str_replace('T', ' ', substr($to_raw,   0, 19));

$is_admin    = Session::haveRight('plugin_appointmentmanager_appointment', UPDATE) || Session::haveRight('config', UPDATE);
$current_uid = (int)Session::getLoginUserID();

// Users without READ right (e.g. requesters using the reschedule calendar) get
// only anonymised busy blocks for the requested tech — no appointment details.
if (!$has_read) {
    $anonymize = true;
} else {
    // Non-admins viewing a specific other tech get anonymised busy blocks only.
    // Requesting all-techs (0) or own calendar → restrict to own data as before.
    $anonymize = false;
    if (!$is_admin) {
        if ($techs_id === 0 || $techs_id === $current_uid) {
            $techs_id = $current_uid;
        } else {
            $anonymize = true;
        }
    }
}

// Fetch appointments
$appointments = ($techs_id > 0)
    ? PluginAppointmentmanagerAppointment::getForTech($techs_id, $from, $to)
    : PluginAppointmentmanagerAppointment::getAll($from, $to);

// Fetch all active types for color mapping
$all_types   = PluginAppointmentmanagerAppointmentType::getAll(false);
$type_by_id  = [];
foreach ($all_types as $t) {
    $type_by_id[(int)$t['id']] = $t;
}

$statuses    = PluginAppointmentmanagerAppointment::getAllStatuses();

$events = [];

// Appointment events
foreach ($appointments as $appt) {
    if (in_array($appt['status'], [
        PluginAppointmentmanagerAppointment::STATUS_CANCELLED,
        PluginAppointmentmanagerAppointment::STATUS_DECLINED,
        PluginAppointmentmanagerAppointment::STATUS_RESCHEDULE_REQUESTED,
    ], true)) {
        continue;
    }

    if ($anonymize) {
        $events[] = [
            'id'      => 'busy_' . (int)$appt['id'],
            'title'   => 'Busy',
            'start'   => str_replace(' ', 'T', $appt['date_start']),
            'end'     => str_replace(' ', 'T', $appt['date_end']),
            'display' => 'background',
            'color'   => '#fca5a5',
        ];
        continue;
    }

    $type      = $type_by_id[(int)$appt['appointmenttypes_id']] ?? null;
    $color     = $type ? $type['color'] : '#0055a4';
    $type_name = $type ? $type['name']  : '';

    $title = '#' . (int)$appt['tickets_id'];
    if ($type_name) {
        $title = $type_name . ' – ' . __('Ticket', 'appointmentmanager') . ' #' . (int)$appt['tickets_id'];
    }

    $events[] = [
        'id'        => (string)(int)$appt['id'],
        'title'     => $title,
        'start'     => str_replace(' ', 'T', $appt['date_start']),
        'end'       => str_replace(' ', 'T', $appt['date_end']),
        'color'     => $color,
        'textColor' => '#ffffff',
        'extendedProps' => [
            'status'    => $appt['status'],
            'ticket_id' => (int)$appt['tickets_id'],
            'type'      => $type_name,
        ],
    ];
}

// Blocked period background events
global $DB;
if ($techs_id > 0) {
    $bp_uids = [$techs_id];
} else {
    $bp_uids = [];
    $enrolled_iter = $DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_plugin_appointmentmanager_enrolled']);
    foreach ($enrolled_iter as $row) {
        $bp_uids[] = (int)$row['users_id'];
    }
}

foreach ($bp_uids as $bp_uid) {
    $blocked = PluginAppointmentmanagerBlockedPeriod::getForCalendar($bp_uid, $from, $to);
    foreach ($blocked as $bp) {
        $events[] = [
            'id'      => 'bp_' . (int)$bp['id'],
            'title'   => $anonymize ? __('Unavailable', 'appointmentmanager') : ($bp['reason'] ?: __('Unavailable', 'appointmentmanager')),
            'start'   => str_replace(' ', 'T', $bp['date_start']),
            'end'     => str_replace(' ', 'T', $bp['date_end']),
            'display' => 'background',
            'color'   => '#cccccc',
        ];
    }
}

// External calendar busy slots (Google / Outlook).
// Always include the logged-in user's own external calendar so their events show
// regardless of which tech is selected. Also include the selected tech's calendar
// if it belongs to a different user.
$ext_uids = array_unique(array_filter([$current_uid, $techs_id > 0 ? $techs_id : 0]));
foreach ($ext_uids as $uid) {
    $busy_slots = PluginAppointmentmanagerCalendarSync::fetchExternalBusySlots($uid, $from, $to, $from_raw, $to_raw);
    foreach ($busy_slots as $slot) {
        $events[] = $slot;
    }
}

echo json_encode($events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
