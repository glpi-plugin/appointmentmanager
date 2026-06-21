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

$ticket_url = rtrim($CFG_GLPI['url_base'] ?? '', '/') . '/front/ticket.form.php?id=' . (int)$appt['tickets_id'];

$description = $comment;
if ($description) {
    $description .= "\n\n";
}
$description .= __('Ticket', 'appointmentmanager') . ': ' . $ticket_url;

// GLPI stores datetimes as UTC
$dtstart = gmdate('Ymd\THis\Z', strtotime($appt['date_start']));
$dtend   = gmdate('Ymd\THis\Z', strtotime($appt['date_end']));
$dtstamp = gmdate('Ymd\THis\Z');
$uid     = 'appt-' . $appt['id'] . '@' . (parse_url($CFG_GLPI['url_base'] ?? '', PHP_URL_HOST) ?: 'glpi');

// RFC 5545 helpers as closures (avoids global function name collision)
$icsEscape = function(string $s): string {
    return str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\;', '\,', '\n', ''], $s);
};
$icsFold = function(string $line): string {
    if (strlen($line) <= 75) {
        return $line;
    }
    $out = '';
    while (strlen($line) > 75) {
        $out  .= substr($line, 0, 75) . "\r\n ";
        $line  = substr($line, 75);
    }
    return $out . $line;
};

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
    'SUMMARY:' . $icsEscape($type_name ?: __('Appointment', 'appointmentmanager')),
    'URL:' . $icsEscape($ticket_url),
];
if ($location_name) {
    $lines[] = 'LOCATION:' . $icsEscape($location_name);
}
$lines[] = 'DESCRIPTION:' . $icsEscape($description);
$lines[] = 'END:VEVENT';
$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="appointment.ics"');
header('Cache-Control: no-cache, no-store');
echo implode('', array_map(fn($l) => $icsFold($l) . "\r\n", $lines));
exit;
