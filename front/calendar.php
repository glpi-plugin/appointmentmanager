<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Session::haveRight('plugin_appointmentmanager_appointment', READ)) {
    Html::displayRightError();
    exit;
}

$plugin_url   = Plugin::getWebDir('appointmentmanager', true);
$is_admin     = Session::haveRight('plugin_appointmentmanager_appointment', UPDATE) || Session::haveRight('config', UPDATE);
$current_user = (int)Session::getLoginUserID();
$selected_tech = $is_admin ? (int)($_GET['techs_id'] ?? 0) : $current_user;

$techs = [];
if ($is_admin) {
    global $DB;
    $iter = $DB->request([
        'SELECT'    => ['glpi_users.id', 'glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname'],
        'FROM'      => 'glpi_profilerights',
        'LEFT JOIN' => [
            'glpi_profiles_users' => [
                'FKEY' => ['glpi_profilerights' => 'profiles_id', 'glpi_profiles_users' => 'profiles_id'],
            ],
            'glpi_users' => [
                'FKEY' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id'],
            ],
        ],
        'WHERE'   => [
            'glpi_profilerights.name' => 'plugin_appointmentmanager_appointment',
            ['glpi_profilerights.rights' => ['>', 0]],
        ],
        'GROUPBY' => 'glpi_users.id',
        'ORDER'   => ['glpi_users.realname ASC', 'glpi_users.name ASC'],
    ]);
    foreach ($iter as $row) {
        if (empty($row['id'])) {
            continue;
        }
        $display = trim($row['realname'] . ' ' . $row['firstname']);
        if ($display === '') {
            $display = $row['name'];
        }
        $techs[(int)$row['id']] = $display;
    }
    if (empty($techs)) {
        $techs[$current_user] = User::getFriendlyNameById($current_user);
    }
}

$events_url = $plugin_url . '/ajax/events.php';
$appt_url   = $plugin_url . '/front/appointment.php';

Html::header(
    __('Appointment Calendar', 'appointmentmanager'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginAppointmentmanagerConfig'
);

Html::displayMessageAfterRedirect();

$glpi_lang  = $_SESSION['glpilanguage'] ?? 'en_GB';
$lang_parts = explode('_', $glpi_lang);
$lang_base  = strtolower($lang_parts[0]);
$fc_locale  = ($lang_base === 'zh') ? strtolower(str_replace('_', '-', $glpi_lang)) : $lang_base;

echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">';
echo '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>';
if ($lang_base !== 'en') {
    echo '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/locales/'
        . htmlspecialchars($fc_locale, ENT_QUOTES, 'UTF-8') . '.global.min.js"></script>';
}

echo '<div class="container-fluid mt-3">';

echo '<div class="d-flex align-items-center mb-3 gap-3">';
echo '<h5 class="mb-0 me-auto">' . __('Appointment Calendar', 'appointmentmanager') . '</h5>';

if ($is_admin && !empty($techs)) {
    echo '<div class="d-flex align-items-center gap-2">';
    echo '<label class="mb-0">' . __('Technician') . ':</label>';
    echo '<select id="amTechSelect" class="form-select form-select-sm" style="width:220px">';
    echo '<option value="0"' . ($selected_tech === 0 ? ' selected' : '') . '>'
        . htmlspecialchars(__('All technicians', 'appointmentmanager'), ENT_QUOTES, 'UTF-8') . '</option>';
    foreach ($techs as $uid => $uname) {
        $sel = $uid === $selected_tech ? ' selected' : '';
        echo '<option value="' . (int)$uid . '"' . $sel . '>' . htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select></div>';
}

echo '<a href="' . htmlspecialchars($plugin_url . '/front/appointment.php', ENT_QUOTES, 'UTF-8')
    . '" class="btn btn-sm btn-primary"><i class="ti ti-calendar-plus me-1"></i>'
    . __('New appointment', 'appointmentmanager') . '</a>';
echo '</div>';

echo '<div id="am-calendar"></div>';
echo '</div>';

// JSON-encode strings for JS
$j_events_url = json_encode($events_url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$j_appt_url   = json_encode($appt_url,   JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$j_tech_id    = json_encode($selected_tech);

$j_fc_locale  = json_encode($fc_locale);

echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    var eventsUrl = ' . $j_events_url . ';
    var apptUrl   = ' . $j_appt_url . ';
    var techId    = ' . $j_tech_id . ';

    var calendar = new FullCalendar.Calendar(document.getElementById("am-calendar"), {
        initialView: "timeGridWeek",
        firstDay: 1,
        locale: ' . $j_fc_locale . ',
        height: "auto",
        slotMinTime: "06:00:00",
        slotMaxTime: "21:00:00",
        nowIndicator: true,
        headerToolbar: {
            left:   "prev,next today",
            center: "title",
            right:  "timeGridDay,timeGridWeek,dayGridMonth"
        },
        events: {
            url:    eventsUrl,
            method: "GET",
            extraParams: function() { return { techs_id: techId }; },
            failure: function() { console.error("[appointmentmanager] Failed to load events"); }
        },
        eventClick: function(info) {
            if (info.event.display === "background") return;
            if (info.event.id) {
                window.location.href = apptUrl + "?id=" + info.event.id;
            }
        },
        dateClick: function(info) {
            var clicked = info.date;
            var evts = calendar.getEvents();
            for (var i = 0; i < evts.length; i++) {
                var ev = evts[i];
                if (ev.display === "background" && ev.start <= clicked && ev.end > clicked) {
                    return;
                }
            }
            window.location.href = apptUrl + "?date_start=" + encodeURIComponent(info.dateStr);
        },
        eventContent: function(arg) {
            if (arg.event.display === "background") return;
            var icons = {
                proposed:             "⏳",
                confirmed:            "✅",
                declined:             "❌",
                reschedule_requested: "🔄",
                cancelled:            "🚫",
                completed:            "🏁"
            };
            var status = arg.event.extendedProps.status || "";
            var icon   = icons[status] || "";
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

    var techSelect = document.getElementById("amTechSelect");
    if (techSelect) {
        techSelect.addEventListener("change", function() {
            techId = parseInt(this.value, 10);
            calendar.refetchEvents();
        });
    }
});
</script>';

Html::footer();
