<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

$token = trim($_GET['token'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    http_response_code(400);
    exit('Invalid token');
}

$appt = PluginAppointmentmanagerAppointment::getByToken($token);
if (!$appt) {
    http_response_code(404);
    exit('Appointment not found');
}

global $CFG_GLPI;

$type_name = '';
if (!empty($appt['appointmenttypes_id'])) {
    $type      = PluginAppointmentmanagerAppointmentType::getById((int)$appt['appointmenttypes_id']);
    $type_name = $type['name'] ?? '';
}
$locations_id  = (int)($appt['locations_id'] ?? 0);
$location_name = $locations_id > 0 ? Dropdown::getDropdownName('glpi_locations', $locations_id) : '';
$comment       = strip_tags($appt['comment'] ?? '');

// GLPI stores datetimes as UTC
$dtstart = gmdate('Ymd\THis\Z', strtotime($appt['date_start']));
$dtend   = gmdate('Ymd\THis\Z', strtotime($appt['date_end']));
$dtstamp = gmdate('Ymd\THis\Z');
$uid     = 'appt-' . $appt['id'] . '@' . (parse_url($CFG_GLPI['url_base'] ?? '', PHP_URL_HOST) ?: 'glpi');

function am_ics_escape(string $s): string {
    return str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\;', '\,', '\n', ''], $s);
}

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//appointmentmanager//EN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:' . $uid,
    'DTSTAMP:' . $dtstamp,
    'DTSTART:' . $dtstart,
    'DTEND:' . $dtend,
    'SUMMARY:' . am_ics_escape($type_name ?: __('Appointment', 'appointmentmanager')),
];
if ($location_name) {
    $lines[] = 'LOCATION:' . am_ics_escape($location_name);
}
if ($comment) {
    $lines[] = 'DESCRIPTION:' . am_ics_escape($comment);
}
$lines[] = 'END:VEVENT';
$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="appointment.ics"');
header('Cache-Control: no-cache, no-store');
echo implode("\r\n", $lines) . "\r\n";
exit;
