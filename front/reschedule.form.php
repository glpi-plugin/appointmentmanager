<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::redirect(Ticket::getSearchURL());
}

$token          = trim($_POST['token'] ?? '');
$date_start_raw = trim($_POST['date_start'] ?? '');
$date_end_raw   = trim($_POST['date_end'] ?? '');

if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    Session::addMessageAfterRedirect(__('Invalid token.', 'appointmentmanager'), false, ERROR);
    Html::redirect(Ticket::getSearchURL());
}

$appt = PluginAppointmentmanagerAppointment::getByToken($token);
if (!$appt) {
    Session::addMessageAfterRedirect(__('Appointment not found.', 'appointmentmanager'), false, ERROR);
    Html::redirect(Ticket::getSearchURL());
}

$ticket_url   = Ticket::getFormURLWithID((int)$appt['tickets_id']);
$plugin_url   = Plugin::getWebDir('appointmentmanager', true);
$current_user = (int)Session::getLoginUserID();
$is_admin     = Session::haveRight('plugin_appointmentmanager_appointment', UPDATE);

if ($appt['users_id_requester'] !== $current_user && !$is_admin) {
    Session::addMessageAfterRedirect(__('You are not allowed to act on this appointment.', 'appointmentmanager'), false, ERROR);
    Html::redirect($ticket_url);
}

if ($appt['status'] !== PluginAppointmentmanagerAppointment::STATUS_PROPOSED) {
    Session::addMessageAfterRedirect(__('This appointment has already been actioned.', 'appointmentmanager'), false, WARNING);
    Html::redirect($ticket_url);
}

$dt_start = DateTime::createFromFormat('Y-m-d\TH:i', $date_start_raw);
$dt_end   = DateTime::createFromFormat('Y-m-d\TH:i', $date_end_raw);

if (!$dt_start || !$dt_end || $dt_end <= $dt_start || $dt_end <= new DateTime()) {
    Session::addMessageAfterRedirect(__('Invalid date selection.', 'appointmentmanager'), false, ERROR);
    Html::redirect($ticket_url);
}

if (!Session::haveRight('config', UPDATE)) {
    $tech_id = (int)$appt['users_id_tech'];
    if (!PluginAppointmentmanagerAvailability::isRangeAvailable($tech_id, $dt_start, $dt_end)) {
        Session::addMessageAfterRedirect(__('The selected time slot is outside the technician\'s availability hours.', 'appointmentmanager'), false, ERROR);
        Html::redirect($plugin_url . '/front/action.php?token=' . urlencode($token) . '&action=reschedule');
    }
}

PluginAppointmentmanagerAppointment::updateStatus(
    (int)$appt['id'],
    PluginAppointmentmanagerAppointment::STATUS_RESCHEDULE_REQUESTED,
    $current_user
);

PluginAppointmentmanagerAppointment::replaceProposalButtons(
    (int)$appt['id'],
    PluginAppointmentmanagerAppointment::STATUS_RESCHEDULE_REQUESTED,
    $current_user
);

PluginAppointmentmanagerAppointment::create([
    'tickets_id'            => (int)$appt['tickets_id'],
    'users_id_tech'         => (int)$appt['users_id_tech'],
    'users_id_requester'    => (int)$appt['users_id_requester'],
    'appointmenttypes_id'   => (int)$appt['appointmenttypes_id'],
    'date_start'            => $dt_start->format('Y-m-d H:i:s'),
    'date_end'              => $dt_end->format('Y-m-d H:i:s'),
    'locations_id'          => (int)($appt['locations_id'] ?? 0),
    'comment'               => '',
    'is_requester_proposed' => ($current_user === (int)$appt['users_id_requester']) ? 1 : 0,
]);

Session::addMessageAfterRedirect(
    __('Your preferred time has been submitted. A new appointment has been proposed on the ticket.', 'appointmentmanager'),
    false,
    INFO
);

Html::redirect($ticket_url);
