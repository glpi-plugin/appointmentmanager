<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

$token  = trim($_GET['token'] ?? '');
$action = trim($_GET['action'] ?? '');

$allowed_acts = ['confirm', 'decline', 'reschedule'];

// ── Error helper ───────────────────────────────────────────────────────────────
function am_action_error(string $msg, string $type = 'danger', string $ticket_url = ''): void {
    Html::header(__('Appointment action', 'appointmentmanager'), $_SERVER['PHP_SELF'], 'tools', 'PluginAppointmentmanagerConfig');
    echo '<div class="container mt-4"><div class="alert alert-' . $type . '">' . $msg;
    if ($ticket_url) {
        echo ' <a href="' . htmlspecialchars($ticket_url, ENT_QUOTES, 'UTF-8') . '">'
            . __('Back to ticket', 'appointmentmanager') . '</a>';
    }
    echo '</div></div>';
    Html::footer();
    exit;
}

// Validate token format
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    am_action_error(__('Invalid or missing appointment token.', 'appointmentmanager'));
}

if (!in_array($action, $allowed_acts, true)) {
    am_action_error(__('Invalid action.', 'appointmentmanager'));
}

// Look up appointment
$appt = PluginAppointmentmanagerAppointment::getByToken($token);

if (!$appt) {
    am_action_error(__('Appointment not found. It may have already been actioned or cancelled.', 'appointmentmanager'), 'warning');
}

$current_user = (int)Session::getLoginUserID();
$is_admin     = Session::haveRight('plugin_appointmentmanager_appointment', UPDATE);
$ticket_url   = Ticket::getFormURLWithID((int)$appt['tickets_id']);

// Auth: requester, the assigned tech, or an admin may act
if ($appt['users_id_requester'] !== $current_user
    && $appt['users_id_tech'] !== $current_user
    && !$is_admin) {
    am_action_error(__('You are not allowed to act on this appointment.', 'appointmentmanager'), 'danger', $ticket_url);
}

// Only the intended recipient may confirm or decline.
// Super-admins (config UPDATE) may override.
if (in_array($action, ['confirm', 'decline'], true)
    && !Session::haveRight('config', UPDATE)
) {
    if (empty($appt['is_requester_proposed'])) {
        // Tech proposed — only the requester may respond
        if ($current_user !== (int)$appt['users_id_requester']) {
            am_action_error(
                __('Only the requester can confirm or decline an appointment proposed by the technician.', 'appointmentmanager'),
                'warning',
                $ticket_url
            );
        }
    } else {
        // Requester proposed — only the assigned tech may respond
        if ($current_user !== (int)$appt['users_id_tech']) {
            am_action_error(
                __('Only the assigned technician can confirm or decline this appointment.', 'appointmentmanager'),
                'warning',
                $ticket_url
            );
        }
    }
}

// Status guard: only proposed appointments can be actioned via token
if ($appt['status'] !== PluginAppointmentmanagerAppointment::STATUS_PROPOSED) {
    $statuses  = PluginAppointmentmanagerAppointment::getAllStatuses();
    $cur_label = htmlspecialchars($statuses[$appt['status']] ?? $appt['status'], ENT_QUOTES, 'UTF-8');
    $msg = sprintf(
        __('This appointment has already been actioned. Current status: <strong>%s</strong>.', 'appointmentmanager'),
        $cur_label
    );
    am_action_error($msg, 'info', $ticket_url);
}

// Reschedule: show calendar picker so the requester can propose a new time
if ($action === 'reschedule') {
    $plugin_url     = Plugin::getWebDir('appointmentmanager', true);
    $csrf           = Session::getNewCSRFToken();
    $events_url     = $plugin_url . '/ajax/events.php';
    $tech_id        = (int)$appt['users_id_tech'];

    $type_name = '';
    if (!empty($appt['appointmenttypes_id'])) {
        $type = PluginAppointmentmanagerAppointmentType::getById((int)$appt['appointmenttypes_id']);
        $type_name = $type ? htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') : '';
    }
    $date_start_fmt = Html::convDateTime($appt['date_start']);
    $date_end_fmt   = Html::convDateTime($appt['date_end']);

    Html::header(__('Reschedule appointment', 'appointmentmanager'), $_SERVER['PHP_SELF'], 'tools', 'PluginAppointmentmanagerConfig');

    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">';
    echo '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>';

    echo '<div class="container mt-4" style="max-width:960px">';

    echo '<div class="card mb-4"><div class="card-body">';
    echo '<h5 class="card-title">' . __('Request a new appointment time', 'appointmentmanager') . '</h5>';
    if ($type_name) {
        echo '<p class="mb-1"><strong>' . __('Type', 'appointmentmanager') . ':</strong> ' . $type_name . '</p>';
    }
    echo '<p class="mb-1"><strong>' . __('Current start', 'appointmentmanager') . ':</strong> ' . $date_start_fmt . '</p>';
    echo '<p class="mb-0"><strong>' . __('Current end', 'appointmentmanager') . ':</strong> ' . $date_end_fmt . '</p>';
    echo '</div></div>';

    echo '<form method="POST" action="' . htmlspecialchars($plugin_url . '/front/reschedule.form.php', ENT_QUOTES, 'UTF-8') . '">';
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
    echo Html::hidden('token', ['value' => htmlspecialchars($token, ENT_QUOTES, 'UTF-8')]);

    echo '<div class="row g-3">';

    echo '<div class="col-lg-8">';
    echo '<p class="text-muted small mb-1">' . __('Click or drag a slot to set your preferred appointment time.', 'appointmentmanager') . '</p>';
    echo '<div id="amRescheduleCalendar"></div>';
    echo '</div>';

    echo '<div class="col-lg-4">';
    echo '<div class="mb-3"><label class="form-label">' . __('Preferred start', 'appointmentmanager') . ' *</label>';
    echo '<input type="datetime-local" id="amRescheduleStart" name="date_start" class="form-control" required></div>';
    echo '<div class="mb-3"><label class="form-label">' . __('Preferred end', 'appointmentmanager') . ' *</label>';
    echo '<input type="datetime-local" id="amRescheduleEnd" name="date_end" class="form-control" required></div>';
    echo '<div class="mb-3"><label class="form-label">' . __('Comment', 'appointmentmanager') . '</label>';
    echo '<textarea name="comment" class="form-control" rows="3" maxlength="1000" placeholder="'
        . htmlspecialchars(__('Reason for the reschedule request (optional)', 'appointmentmanager'), ENT_QUOTES, 'UTF-8')
        . '"></textarea></div>';
    echo '<button type="submit" class="btn btn-primary w-100">';
    echo '<i class="ti ti-calendar-event me-1"></i>' . __('Propose this time', 'appointmentmanager');
    echo '</button>';
    echo '<a href="' . htmlspecialchars($ticket_url, ENT_QUOTES, 'UTF-8') . '" class="btn btn-secondary w-100 mt-2">' . __('Cancel') . '</a>';
    echo '</div>';

    echo '</div>';
    echo '</form>';

    $j_events_url = json_encode($events_url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $j_tech_id    = json_encode($tech_id);

    echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    var calEl   = document.getElementById("amRescheduleCalendar");
    var startEl = document.getElementById("amRescheduleStart");
    var endEl   = document.getElementById("amRescheduleEnd");

    function toLocalInput(d) {
        var pad = function(n) { return n < 10 ? "0" + n : "" + n; };
        return d.getFullYear() + "-" + pad(d.getMonth()+1) + "-" + pad(d.getDate())
             + "T" + pad(d.getHours()) + ":" + pad(d.getMinutes());
    }

    var calendar = new FullCalendar.Calendar(calEl, {
        initialView: "timeGridWeek",
        firstDay: 1,
        height: 500,
        slotMinTime: "06:00:00",
        slotMaxTime: "21:00:00",
        nowIndicator: true,
        headerToolbar: { left: "prev,next today", center: "title", right: "" },
        selectable: true,
        selectMirror: true,
        select: function(info) {
            startEl.value = toLocalInput(info.start);
            endEl.value   = toLocalInput(info.end);
        },
        selectAllow: function(selectInfo) {
            var events = calendar.getEvents();
            for (var i = 0; i < events.length; i++) {
                var ev = events[i];
                if (ev.display === "background" && ev.start < selectInfo.end && ev.end > selectInfo.start) {
                    return false;
                }
            }
            return true;
        },
        events: {
            url: ' . $j_events_url . ',
            method: "GET",
            extraParams: { techs_id: ' . $j_tech_id . ' },
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
    });
    calendar.render();
});
</script>';

    echo '</div>';
    Html::footer();
    exit;
}

// Map action to status (confirm and decline only — reschedule is handled above)
$status_map = [
    'confirm' => PluginAppointmentmanagerAppointment::STATUS_CONFIRMED,
    'decline' => PluginAppointmentmanagerAppointment::STATUS_DECLINED,
];
$new_status = $status_map[$action] ?? null;

if ($new_status === null) {
    am_action_error(__('Invalid action.', 'appointmentmanager'));
}

// Apply and redirect — no Html::header() / Html::footer() on the success path
$ok = PluginAppointmentmanagerAppointment::updateStatus((int)$appt['id'], $new_status, $current_user);

if ($ok) {
    PluginAppointmentmanagerAppointment::replaceProposalButtons(
        (int)$appt['id'],
        $new_status,
        $current_user
    );
    Session::addMessageAfterRedirect(
        __('Your response has been recorded.', 'appointmentmanager'),
        false,
        INFO
    );
} else {
    Session::addMessageAfterRedirect(
        __('Could not process the action. Please contact support.', 'appointmentmanager'),
        false,
        ERROR
    );
}

Html::redirect($ticket_url);
