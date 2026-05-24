<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

if (!Session::haveRight('plugin_appointmentmanager_appointment', READ)) {
    echo json_encode(['error' => 'Permission denied'], JSON_HEX_TAG | JSON_HEX_AMP);
    http_response_code(403);
    exit;
}

$from     = trim($_GET['start'] ?? '');
$to       = trim($_GET['end']   ?? '');
$techs_id = (int)($_GET['techs_id'] ?? 0);

if (!$from || !$to) {
    echo json_encode(['error' => 'Missing start/end'], JSON_HEX_TAG | JSON_HEX_AMP);
    http_response_code(400);
    exit;
}

$is_admin    = Session::haveRight('plugin_appointmentmanager_appointment', UPDATE) || Session::haveRight('config', UPDATE);
$current_uid = (int)Session::getLoginUserID();

// Non-admins can only see their own appointments
if (!$is_admin) {
    $techs_id = $current_uid;
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
    $type      = $type_by_id[(int)$appt['appointmenttypes_id']] ?? null;
    $color     = $type ? $type['color'] : '#0055a4';
    $type_name = $type ? $type['name']  : '';

    $title = '#' . (int)$appt['tickets_id'];
    if ($type_name) {
        $title = $type_name . ' – ' . __('Ticket', 'appointmentmanager') . ' #' . (int)$appt['tickets_id'];
    }

    $opacity = in_array($appt['status'], [
        PluginAppointmentmanagerAppointment::STATUS_CANCELLED,
        PluginAppointmentmanagerAppointment::STATUS_DECLINED,
    ], true) ? '66' : '';

    $events[] = [
        'id'        => (string)(int)$appt['id'],
        'title'     => $title,
        'start'     => str_replace(' ', 'T', $appt['date_start']),
        'end'       => str_replace(' ', 'T', $appt['date_end']),
        'color'     => $color . $opacity,
        'textColor' => '#ffffff',
        'extendedProps' => [
            'status'    => $appt['status'],
            'ticket_id' => (int)$appt['tickets_id'],
            'type'      => $type_name,
        ],
    ];
}

// Blocked period background events
if ($techs_id > 0) {
    $blocked = PluginAppointmentmanagerBlockedPeriod::getForCalendar($techs_id, $from, $to);
    foreach ($blocked as $bp) {
        $events[] = [
            'id'      => 'bp_' . (int)$bp['id'],
            'title'   => $bp['reason'] ?: __('Unavailable', 'appointmentmanager'),
            'start'   => str_replace(' ', 'T', $bp['date_start']),
            'end'     => str_replace(' ', 'T', $bp['date_end']),
            'display' => 'background',
            'color'   => '#cccccc',
        ];
    }

    // External calendar busy slots (Google / Outlook)
    $busy_slots = PluginAppointmentmanagerCalendarSync::fetchExternalBusySlots($techs_id, $from, $to);
    foreach ($busy_slots as $slot) {
        $events[] = $slot;
    }
}

echo json_encode($events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
