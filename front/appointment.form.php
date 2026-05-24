<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Session::haveRight('plugin_appointmentmanager_appointment', CREATE)) {
    Html::displayRightError();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::redirect(Ticket::getSearchURL());
}

$tickets_id          = (int)($_POST['tickets_id'] ?? 0);
$appointment_id      = (int)($_POST['appointment_id'] ?? 0);
$appointmenttypes_id = (int)($_POST['appointmenttypes_id'] ?? 0);
$date_start_raw      = trim($_POST['date_start'] ?? '');
$date_end_raw        = trim($_POST['date_end'] ?? '');
$locations_id        = (int)($_POST['locations_id'] ?? 0);
$comment             = strip_tags(trim($_POST['comment'] ?? ''));

$plugin_url = Plugin::getWebDir('appointmentmanager', true);

// Validate ticket
if ($tickets_id <= 0) {
    Session::addMessageAfterRedirect(__('Invalid ticket.', 'appointmentmanager'), false, ERROR);
    Html::redirect(Ticket::getSearchURL());
}

$ticket_obj = new Ticket();
if (!$ticket_obj->getFromDB($tickets_id) || !$ticket_obj->canViewItem()) {
    Session::addMessageAfterRedirect(__('Ticket not found.', 'appointmentmanager'), false, ERROR);
    Html::redirect(Ticket::getSearchURL());
}

// Validate dates
$dt_start = DateTime::createFromFormat('Y-m-d\TH:i', $date_start_raw);
$dt_end   = DateTime::createFromFormat('Y-m-d\TH:i', $date_end_raw);

if (!$dt_start || !$dt_end) {
    Session::addMessageAfterRedirect(__('Invalid date format.', 'appointmentmanager'), false, ERROR);
    Html::redirect($plugin_url . '/front/appointment.php?tickets_id=' . $tickets_id);
}

if ($dt_end <= $dt_start) {
    Session::addMessageAfterRedirect(__('End date must be after start date.', 'appointmentmanager'), false, ERROR);
    Html::redirect($plugin_url . '/front/appointment.php?tickets_id=' . $tickets_id);
}

if ($dt_end <= new DateTime()) {
    Session::addMessageAfterRedirect(__('Appointment cannot be set in the past.', 'appointmentmanager'), false, ERROR);
    Html::redirect($plugin_url . '/front/appointment.php?tickets_id=' . $tickets_id);
}

if (!Session::haveRight('config', UPDATE)) {
    $tech_id = (int)Session::getLoginUserID();
    if (!PluginAppointmentmanagerAvailability::isRangeAvailable($tech_id, $dt_start, $dt_end)) {
        Session::addMessageAfterRedirect(__('The selected time slot is outside the technician\'s availability hours.', 'appointmentmanager'), false, ERROR);
        Html::redirect($plugin_url . '/front/appointment.php?tickets_id=' . $tickets_id);
    }
}

if ($appointment_id > 0) {
    // Update mode
    $existing = PluginAppointmentmanagerAppointment::getById($appointment_id);
    if (!$existing || (int)$existing['tickets_id'] !== $tickets_id) {
        Session::addMessageAfterRedirect(__('Invalid appointment.', 'appointmentmanager'), false, ERROR);
        Html::redirect(Ticket::getFormURLWithID($tickets_id));
    }

    PluginAppointmentmanagerAppointment::updateDetails($appointment_id, [
        'appointmenttypes_id' => $appointmenttypes_id,
        'date_start'          => $dt_start->format('Y-m-d H:i:s'),
        'date_end'            => $dt_end->format('Y-m-d H:i:s'),
        'locations_id'        => $locations_id,
    ], (int)Session::getLoginUserID());

    Session::addMessageAfterRedirect(
        __('Appointment updated. The follow-up has been refreshed.', 'appointmentmanager'),
        false,
        INFO
    );
} else {
    // Create mode — resolve requester from ticket actors
    global $DB;
    $users_id_requester = 0;
    $iter = $DB->request([
        'FROM'  => 'glpi_tickets_users',
        'WHERE' => [
            'tickets_id' => $tickets_id,
            'type'       => CommonITILActor::REQUESTER,
        ],
        'LIMIT' => 1,
    ]);
    if ($iter->count() > 0) {
        $users_id_requester = (int)$iter->current()['users_id'];
    }

    PluginAppointmentmanagerAppointment::create([
        'tickets_id'          => $tickets_id,
        'users_id_tech'       => (int)Session::getLoginUserID(),
        'users_id_requester'  => $users_id_requester,
        'appointmenttypes_id' => $appointmenttypes_id,
        'date_start'          => $dt_start->format('Y-m-d H:i:s'),
        'date_end'            => $dt_end->format('Y-m-d H:i:s'),
        'locations_id'        => $locations_id,
        'comment'             => $comment,
    ]);

    Session::addMessageAfterRedirect(
        __('Appointment proposed. A follow-up has been posted on the ticket.', 'appointmentmanager'),
        false,
        INFO
    );
}

Html::redirect(Ticket::getFormURLWithID($tickets_id));
