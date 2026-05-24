<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Session::haveRight('plugin_appointmentmanager_appointment', READ)) {
    Html::displayRightError();
    exit;
}

$id         = (int)($_GET['id'] ?? 0);
$tickets_id = (int)($_GET['tickets_id'] ?? 0);
$date_start = $_GET['date_start'] ?? '';

$is_view   = $id > 0;
$can_write = Session::haveRight('plugin_appointmentmanager_appointment', CREATE);

$appt  = null;
$ticket = null;

if ($is_view) {
    $appt = PluginAppointmentmanagerAppointment::getById($id);
    if (!$appt) {
        Html::displayNotFoundError();
        exit;
    }
    $tickets_id = (int)$appt['tickets_id'];
}

if ($tickets_id > 0) {
    $ticket_obj = new Ticket();
    if (!$ticket_obj->getFromDB($tickets_id)) {
        Session::addMessageAfterRedirect(__('Ticket not found.', 'appointmentmanager'), false, ERROR);
        Html::redirect(Ticket::getSearchURL());
    }
    $ticket = $ticket_obj->fields;
}

$plugin_url  = Plugin::getWebDir('appointmentmanager', true);
$types       = PluginAppointmentmanagerAppointmentType::getAll(true);
$statuses    = PluginAppointmentmanagerAppointment::getAllStatuses();

Html::header(
    $is_view ? __('Appointment', 'appointmentmanager') : __('New appointment', 'appointmentmanager'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginAppointmentmanagerConfig'
);

Html::displayMessageAfterRedirect();

echo '<div class="container-fluid mt-3" style="max-width:800px">';

// Breadcrumb
if ($ticket) {
    echo '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
    echo '<li class="breadcrumb-item"><a href="' . htmlspecialchars(Ticket::getFormURLWithID($tickets_id), ENT_QUOTES, 'UTF-8') . '">';
    echo __('Ticket') . ' #' . (int)$tickets_id . ' – ' . htmlspecialchars($ticket['name'] ?? '', ENT_QUOTES, 'UTF-8');
    echo '</a></li>';
    echo '<li class="breadcrumb-item active">' . ($is_view ? __('Appointment', 'appointmentmanager') : __('New appointment', 'appointmentmanager')) . '</li>';
    echo '</ol></nav>';
}

echo '<div class="card">';
echo '<div class="card-header"><h5 class="mb-0">';
if ($is_view && $appt) {
    $badge_class = PluginAppointmentmanagerAppointment::getStatusBadgeClass($appt['status']);
    echo __('Appointment', 'appointmentmanager') . ' #' . (int)$appt['id']
        . ' <span class="badge ' . $badge_class . ' ms-2">'
        . htmlspecialchars($statuses[$appt['status']] ?? $appt['status'], ENT_QUOTES, 'UTF-8')
        . '</span>';
} else {
    echo __('New appointment', 'appointmentmanager');
}
echo '</h5></div>';

echo '<div class="card-body">';

if ($is_view && $appt) {
    // View mode
    $type = PluginAppointmentmanagerAppointmentType::getById((int)$appt['appointmenttypes_id']);
    $tech_name = User::getFriendlyNameById((int)$appt['users_id_tech']);
    $req_name  = User::getFriendlyNameById((int)$appt['users_id_requester']);

    echo '<dl class="row">';
    echo '<dt class="col-sm-3">' . __('Type') . '</dt><dd class="col-sm-9">';
    if ($type) {
        echo '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:'
            . htmlspecialchars($type['color'], ENT_QUOTES, 'UTF-8') . ';margin-right:6px"></span>';
        echo htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8');
    }
    echo '</dd>';
    echo '<dt class="col-sm-3">' . __('Technician') . '</dt><dd class="col-sm-9">' . htmlspecialchars($tech_name, ENT_QUOTES, 'UTF-8') . '</dd>';
    echo '<dt class="col-sm-3">' . __('Requester') . '</dt><dd class="col-sm-9">' . htmlspecialchars($req_name, ENT_QUOTES, 'UTF-8') . '</dd>';
    echo '<dt class="col-sm-3">' . __('Start') . '</dt><dd class="col-sm-9">' . Html::convDateTime($appt['date_start']) . '</dd>';
    echo '<dt class="col-sm-3">' . __('End') . '</dt><dd class="col-sm-9">' . Html::convDateTime($appt['date_end']) . '</dd>';
    if ($appt['location']) {
        echo '<dt class="col-sm-3">' . __('Location') . '</dt><dd class="col-sm-9">' . htmlspecialchars($appt['location'], ENT_QUOTES, 'UTF-8') . '</dd>';
    }
    if ($appt['comment']) {
        echo '<dt class="col-sm-3">' . __('Comment') . '</dt><dd class="col-sm-9">' . nl2br(htmlspecialchars($appt['comment'], ENT_QUOTES, 'UTF-8')) . '</dd>';
    }
    echo '</dl>';

    // Cancel / complete actions for tech / admin
    $current_user = (int)Session::getLoginUserID();
    $is_tech_own  = PluginAppointmentmanagerAppointment::techOwns((int)$appt['id'], $current_user);
    $can_manage   = Session::haveRight('plugin_appointmentmanager_appointment', UPDATE);

    if (($is_tech_own || $can_manage) && in_array($appt['status'], [
        PluginAppointmentmanagerAppointment::STATUS_PROPOSED,
        PluginAppointmentmanagerAppointment::STATUS_CONFIRMED,
        PluginAppointmentmanagerAppointment::STATUS_RESCHEDULE_REQUESTED,
    ], true)) {
        echo '<hr>';
        if ($appt['status'] === PluginAppointmentmanagerAppointment::STATUS_CONFIRMED) {
            echo '<form method="POST" action="' . htmlspecialchars($plugin_url . '/ajax/appointment.php', ENT_QUOTES, 'UTF-8') . '" class="d-inline">';
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('action', ['value' => 'complete']);
            echo Html::hidden('id', ['value' => (int)$appt['id']]);
            echo '<button type="submit" class="btn btn-success me-2"><i class="ti ti-check me-1"></i>' . __('Mark completed', 'appointmentmanager') . '</button>';
            echo '</form>';
        }
        echo '<form method="POST" action="' . htmlspecialchars($plugin_url . '/ajax/appointment.php', ENT_QUOTES, 'UTF-8') . '" class="d-inline"'
            . ' onsubmit="return confirm(\'' . addslashes(__('Cancel this appointment?', 'appointmentmanager')) . '\')">';
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('action', ['value' => 'cancel']);
        echo Html::hidden('id', ['value' => (int)$appt['id']]);
        echo Html::hidden('redirect', ['value' => Ticket::getFormURLWithID($tickets_id)]);
        echo '<button type="submit" class="btn btn-danger"><i class="ti ti-x me-1"></i>' . __('Cancel appointment', 'appointmentmanager') . '</button>';
        echo '</form>';
    }

} else {
    // Create mode
    if (!$can_write) {
        echo '<div class="alert alert-danger">' . __('You do not have permission to create appointments.', 'appointmentmanager') . '</div>';
    } else {
        echo '<form method="POST" action="' . htmlspecialchars($plugin_url . '/front/appointment.form.php', ENT_QUOTES, 'UTF-8') . '">';
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('tickets_id', ['value' => $tickets_id]);

        // Type
        echo '<div class="mb-3"><label class="form-label">' . __('Appointment type', 'appointmentmanager') . ' *</label>';
        echo '<select name="appointmenttypes_id" class="form-select" required>';
        echo '<option value="">' . __('Select a type…', 'appointmentmanager') . '</option>';
        foreach ($types as $type) {
            echo '<option value="' . (int)$type['id'] . '">' . htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select></div>';

        // Start / End
        $default_start = '';
        if ($date_start) {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $date_start);
            if (!$dt) {
                $dt = DateTime::createFromFormat('Y-m-d', $date_start);
            }
            if ($dt) {
                $default_start = $dt->format('Y-m-d\TH:i');
            }
        }

        echo '<div class="row mb-3">';
        echo '<div class="col"><label class="form-label">' . __('Start') . ' *</label>';
        echo '<input type="datetime-local" name="date_start" class="form-control" required value="'
            . htmlspecialchars($default_start, ENT_QUOTES, 'UTF-8') . '"></div>';
        echo '<div class="col"><label class="form-label">' . __('End') . ' *</label>';
        echo '<input type="datetime-local" name="date_end" class="form-control" required></div>';
        echo '</div>';

        // Location
        echo '<div class="mb-3"><label class="form-label">' . __('Location') . '</label>';
        echo '<input type="text" name="location" class="form-control" maxlength="255" placeholder="'
            . htmlspecialchars(__('Office, building, or remote', 'appointmentmanager'), ENT_QUOTES, 'UTF-8') . '"></div>';

        // Comment
        echo '<div class="mb-3"><label class="form-label">' . __('Comment') . '</label>';
        echo '<textarea name="comment" class="form-control" rows="3"></textarea></div>';

        echo '<button type="submit" class="btn btn-primary"><i class="ti ti-calendar-plus me-1"></i>'
            . __('Propose appointment', 'appointmentmanager') . '</button>';
        echo ' <a href="' . htmlspecialchars(Ticket::getFormURLWithID($tickets_id), ENT_QUOTES, 'UTF-8') . '" class="btn btn-secondary ms-2">'
            . __('Cancel') . '</a>';
        echo '</form>';
    }
}

echo '</div></div>'; // card-body, card

// Past appointments on this ticket
if ($tickets_id > 0) {
    $past = PluginAppointmentmanagerAppointment::getForTicket($tickets_id);
    if (!empty($past)) {
        echo '<div class="card mt-3"><div class="card-header"><h6 class="mb-0">'
            . __('All appointments for this ticket', 'appointmentmanager') . '</h6></div>';
        echo '<div class="card-body p-0"><table class="table table-sm mb-0"><thead><tr>'
            . '<th>#</th><th>' . __('Type') . '</th><th>' . __('Start') . '</th><th>' . __('End') . '</th>'
            . '<th>' . __('Status') . '</th><th></th>'
            . '</tr></thead><tbody>';
        foreach ($past as $pa) {
            $type_row = PluginAppointmentmanagerAppointmentType::getById((int)$pa['appointmenttypes_id']);
            $badge    = PluginAppointmentmanagerAppointment::getStatusBadgeClass($pa['status']);
            echo '<tr>';
            echo '<td>' . (int)$pa['id'] . '</td>';
            echo '<td>';
            if ($type_row) {
                echo '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'
                    . htmlspecialchars($type_row['color'], ENT_QUOTES, 'UTF-8') . ';margin-right:5px"></span>'
                    . htmlspecialchars($type_row['name'], ENT_QUOTES, 'UTF-8');
            }
            echo '</td>';
            echo '<td>' . Html::convDateTime($pa['date_start']) . '</td>';
            echo '<td>' . Html::convDateTime($pa['date_end']) . '</td>';
            echo '<td><span class="badge ' . $badge . '">'
                . htmlspecialchars($statuses[$pa['status']] ?? $pa['status'], ENT_QUOTES, 'UTF-8') . '</span></td>';
            echo '<td><a href="' . htmlspecialchars($plugin_url . '/front/appointment.php?id=' . (int)$pa['id'], ENT_QUOTES, 'UTF-8')
                . '" class="btn btn-sm btn-outline-secondary">' . __('View') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
}

echo '</div>'; // container-fluid

Html::footer();
