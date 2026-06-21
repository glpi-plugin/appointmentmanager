<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

function am_build_display_name(array $row): string {
    $display = trim($row['realname'] . ' ' . $row['firstname']);
    return $display !== '' ? $display : $row['name'];
}

function am_get_tech_users(): array {
    global $DB;

    $iter = $DB->request([
        'SELECT'    => ['glpi_users.id', 'glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname'],
        'FROM'      => 'glpi_plugin_appointmentmanager_enrolled',
        'LEFT JOIN' => [
            'glpi_users' => [
                'FKEY' => ['glpi_plugin_appointmentmanager_enrolled' => 'users_id', 'glpi_users' => 'id'],
            ],
        ],
        'WHERE' => ['NOT' => ['glpi_users.id' => null]],
        'ORDER' => ['glpi_users.realname ASC', 'glpi_users.name ASC'],
    ]);

    $users = [];
    foreach ($iter as $row) {
        if (empty($row['id'])) {
            continue;
        }
        $users[(int)$row['id']] = am_build_display_name($row);
    }
    return $users;
}

function am_get_unenrolled_tech_users(): array {
    global $DB;

    $enrolled_ids = [];
    foreach ($DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_plugin_appointmentmanager_enrolled']) as $e) {
        $enrolled_ids[] = (int)$e['users_id'];
    }

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
        'WHERE'   => array_merge(
            [
                'glpi_profilerights.name' => 'plugin_appointmentmanager_appointment',
                ['glpi_profilerights.rights' => ['>', 0]],
            ],
            $enrolled_ids ? [['NOT' => ['glpi_users.id' => $enrolled_ids]]] : []
        ),
        'GROUPBY' => 'glpi_users.id',
        'ORDER'   => ['glpi_users.realname ASC', 'glpi_users.name ASC'],
    ]);

    $users = [];
    foreach ($iter as $row) {
        if (empty($row['id'])) {
            continue;
        }
        $users[(int)$row['id']] = am_build_display_name($row);
    }
    return $users;
}

function am_is_valid_tech(int $users_id): bool {
    global $DB;
    $iter = $DB->request([
        'SELECT'    => ['glpi_users.id'],
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
            'glpi_profilerights.name'   => 'plugin_appointmentmanager_appointment',
            ['glpi_profilerights.rights' => ['>', 0]],
            'glpi_users.id'             => $users_id,
        ],
        'LIMIT' => 1,
    ]);
    return count($iter) > 0;
}

$plugin_url           = Plugin::getWebDir('appointmentmanager', true);
$is_glpi_admin        = Session::haveRight('config', UPDATE);
$can_manage_types     = Session::haveRight('plugin_appointmentmanager_type', UPDATE) || $is_glpi_admin;
$can_manage_techs     = Session::haveRight('plugin_appointmentmanager_technician', UPDATE) || $is_glpi_admin;
$can_manage_all_avail = $is_glpi_admin;
$can_manage_own_avail = Session::haveRight('plugin_appointmentmanager_availability', UPDATE) || $can_manage_all_avail;
$can_use_calendar     = Session::haveRight('plugin_appointmentmanager_calendar', READ) || $is_glpi_admin;
$can_self_enroll      = Session::haveRight('plugin_appointmentmanager_technician', READ);
$is_admin             = $can_manage_techs;

if (!isset($_GET['tab'])) {
    if ($can_manage_types)         { $tab = 'types'; }
    elseif ($can_manage_own_avail) { $tab = 'availability'; }
    elseif ($can_use_calendar)     { $tab = 'integrations'; }
    else                           { $tab = 'types'; }
} else {
    $tab = $_GET['tab'];
}
$allowed_tabs = ['types', 'technicians', 'availability', 'blocked', 'integrations'];
if (!in_array($tab, $allowed_tabs, true)) {
    $tab = 'types';
}

$csrf = Session::getNewCSRFToken();

$tab_permissions = [
    'types'        => $can_manage_types,
    'technicians'  => $can_manage_techs || $can_self_enroll,
    'availability' => $can_manage_own_avail,
    'blocked'      => $can_manage_own_avail,
    'integrations' => $can_use_calendar,
];
if (!($tab_permissions[$tab] ?? false)) {
    Html::displayRightError();
    exit;
}

// ── POST handlers ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_type' || $action === 'edit_type' || $action === 'delete_type') {
        if (!$can_manage_types) {
            Html::displayRightError();
            exit;
        }
    }

    if ($action === 'add_type' || $action === 'edit_type') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            Session::addMessageAfterRedirect(__('Name is required.', 'appointmentmanager'), false, ERROR);
            Html::back();
        }
        if ($action === 'add_type') {
            PluginAppointmentmanagerAppointmentType::create([
                'name'       => $name,
                'color'      => $_POST['color'] ?? '#0055a4',
                'icon'       => $_POST['icon'] ?? '',
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            ]);
            Session::addMessageAfterRedirect(__('Appointment type added.', 'appointmentmanager'), false, INFO);
        } else {
            $id = (int)($_POST['id'] ?? 0);
            PluginAppointmentmanagerAppointmentType::update($id, [
                'name'       => $name,
                'color'      => $_POST['color'] ?? '#0055a4',
                'icon'       => $_POST['icon'] ?? '',
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            ]);
            Session::addMessageAfterRedirect(__('Appointment type updated.', 'appointmentmanager'), false, INFO);
        }
        Html::redirect($plugin_url . '/front/config.php?tab=types');
    }

    if ($action === 'delete_type') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = PluginAppointmentmanagerAppointmentType::getById($id);
        if ($row && $row['is_default']) {
            Session::addMessageAfterRedirect(__('Built-in appointment types cannot be deleted.', 'appointmentmanager'), false, ERROR);
        } else {
            PluginAppointmentmanagerAppointmentType::delete($id);
            Session::addMessageAfterRedirect(__('Appointment type deactivated.', 'appointmentmanager'), false, INFO);
        }
        Html::redirect($plugin_url . '/front/config.php?tab=types');
    }

    if ($action === 'enroll_tech') {
        global $DB;
        $users_id = (int)($_POST['users_id'] ?? 0);
        $is_self  = $users_id === (int)Session::getLoginUserID();
        if (!$is_admin && !($is_self && $can_self_enroll)) {
            Html::displayRightError();
            exit;
        }
        if ($users_id > 0 && am_is_valid_tech($users_id)) {
            $DB->insert('glpi_plugin_appointmentmanager_enrolled', [
                'users_id'      => $users_id,
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
            Session::addMessageAfterRedirect(__('Technician enrolled.', 'appointmentmanager'), false, INFO);
        } else {
            Session::addMessageAfterRedirect(__('Invalid technician.', 'appointmentmanager'), false, ERROR);
        }
        Html::redirect($plugin_url . '/front/config.php?tab=technicians');
    }

    if ($action === 'unenroll_tech') {
        global $DB;
        $users_id = (int)($_POST['users_id'] ?? 0);
        $is_self  = $users_id === (int)Session::getLoginUserID();
        if (!$is_admin && !($is_self && $can_self_enroll)) {
            Html::displayRightError();
            exit;
        }
        if ($users_id > 0) {
            $DB->delete('glpi_plugin_appointmentmanager_enrolled', ['users_id' => $users_id]);
            Session::addMessageAfterRedirect(__('Technician removed.', 'appointmentmanager'), false, INFO);
        }
        Html::redirect($plugin_url . '/front/config.php?tab=technicians');
    }

    if ($action === 'save_all_days') {
        $target_user = $can_manage_all_avail ? (int)($_POST['users_id'] ?? Session::getLoginUserID()) : (int)Session::getLoginUserID();
        $days = [];
        for ($d = 1; $d <= 7; $d++) {
            $days[] = [
                'day_of_week' => $d,
                'time_start'  => $_POST['time_start_' . $d] ?? '08:00:00',
                'time_end'    => $_POST['time_end_' . $d]   ?? '18:00:00',
                'is_active'   => !empty($_POST['is_active_' . $d]),
            ];
        }
        PluginAppointmentmanagerAvailability::saveAll($target_user, $days);
        Session::addMessageAfterRedirect(__('Availability saved.', 'appointmentmanager'), false, INFO);
        Html::redirect($plugin_url . '/front/config.php?tab=availability&users_id=' . $target_user);
    }

    if ($action === 'add_blocked') {
        $target_user = $can_manage_all_avail ? (int)($_POST['users_id'] ?? Session::getLoginUserID()) : (int)Session::getLoginUserID();
        $date_start  = $_POST['date_start'] ?? '';
        $date_end    = $_POST['date_end']   ?? '';
        if ($date_start && $date_end && $date_start < $date_end) {
            PluginAppointmentmanagerBlockedPeriod::create($target_user, $date_start, $date_end, $_POST['reason'] ?? '');
            Session::addMessageAfterRedirect(__('Blocked period added.', 'appointmentmanager'), false, INFO);
        } else {
            Session::addMessageAfterRedirect(__('Invalid date range for blocked period.', 'appointmentmanager'), false, ERROR);
        }
        Html::redirect($plugin_url . '/front/config.php?tab=blocked&users_id=' . $target_user);
    }

    if ($action === 'delete_blocked') {
        $id          = (int)($_POST['id'] ?? 0);
        $target_user = $can_manage_all_avail ? (int)($_POST['users_id'] ?? Session::getLoginUserID()) : (int)Session::getLoginUserID();
        PluginAppointmentmanagerBlockedPeriod::delete($id, (int)Session::getLoginUserID());
        Session::addMessageAfterRedirect(__('Blocked period removed.', 'appointmentmanager'), false, INFO);
        Html::redirect($plugin_url . '/front/config.php?tab=blocked&users_id=' . $target_user);
    }

    if ($action === 'save_oauth_settings' && $is_admin) {
        foreach (['google', 'microsoft'] as $provider) {
            PluginAppointmentmanagerOAuthProvider::saveSettings($provider, [
                'client_id'     => $_POST[$provider . '_client_id']     ?? '',
                'client_secret' => $_POST[$provider . '_client_secret'] ?? '',
                'tenant_id'     => $_POST[$provider . '_tenant_id']     ?? 'common',
            ]);
        }
        Session::addMessageAfterRedirect(__('OAuth settings saved.', 'appointmentmanager'), false, INFO);
        Html::redirect($plugin_url . '/front/config.php?tab=integrations');
    }

    if ($action === 'disconnect_calendar') {
        if (!$can_use_calendar) {
            Html::displayRightError();
            exit;
        }
        $provider = trim($_POST['provider'] ?? '');
        if (in_array($provider, ['google', 'microsoft'], true)) {
            PluginAppointmentmanagerOAuthProvider::deleteToken((int)Session::getLoginUserID(), $provider);
            Session::addMessageAfterRedirect(__('Calendar disconnected.', 'appointmentmanager'), false, INFO);
        }
        Html::redirect($plugin_url . '/front/config.php?tab=integrations');
    }

    if ($action === 'backfill_sync' && $is_admin) {
        $uid_filter = (int)($_POST['users_id_filter'] ?? 0);
        $result = PluginAppointmentmanagerCalendarSync::backfillSync($uid_filter);
        $msg = sprintf(
            __('%d appointment(s) synced to calendar, %d failed.', 'appointmentmanager'),
            $result['synced'],
            $result['failed']
        );
        Session::addMessageAfterRedirect($msg, false, $result['failed'] > 0 ? WARNING : INFO);
        Html::redirect($plugin_url . '/front/config.php?tab=integrations');
    }
}

// ── Render ─────────────────────────────────────────────────────────────────────

Html::header(__('Appointment Manager – Settings', 'appointmentmanager'), $_SERVER['PHP_SELF'], 'config', 'plugins');
Html::displayMessageAfterRedirect();

echo '<div class="container-fluid mt-3">';

// Tab nav
echo '<ul class="nav nav-tabs mb-3">';
$tab_labels = [
    'types'        => __('Appointment Types', 'appointmentmanager'),
    'technicians'  => __('Technicians', 'appointmentmanager'),
    'availability' => __('Technician Availability', 'appointmentmanager'),
    'blocked'      => __('Blocked Periods', 'appointmentmanager'),
    'integrations' => __('Calendar Integrations', 'appointmentmanager'),
];
foreach ($tab_labels as $key => $label) {
    if (!($tab_permissions[$key] ?? false)) {
        continue;
    }
    $active = $tab === $key ? ' active' : '';
    echo '<li class="nav-item"><a class="nav-link' . $active . '" href="'
        . htmlspecialchars($plugin_url . '/front/config.php?tab=' . $key, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
}
echo '</ul>';

// ── Tab: Types ─────────────────────────────────────────────────────────────────
if ($tab === 'types') {
    $types = PluginAppointmentmanagerAppointmentType::getAll(false);

    echo '<div class="card"><div class="card-header d-flex justify-content-between align-items-center">';
    echo '<h5 class="mb-0">' . __('Appointment Types', 'appointmentmanager') . '</h5>';
    echo '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#amAddTypeModal">'
        . '<i class="ti ti-plus me-1"></i>' . __('Add type', 'appointmentmanager') . '</button>';
    echo '</div><div class="card-body p-0">';
    echo '<table class="table table-hover mb-0"><thead><tr>'
        . '<th>' . __('Color') . '</th>'
        . '<th>' . __('Name') . '</th>'
        . '<th>' . __('Icon') . '</th>'
        . '<th>' . __('Order') . '</th>'
        . '<th>' . __('Active') . '</th>'
        . '<th></th>'
        . '</tr></thead><tbody>';

    foreach ($types as $type) {
        $eid = (int)$type['id'];
        echo '<tr>';
        echo '<td><span style="display:inline-block;width:24px;height:24px;border-radius:4px;background:'
            . htmlspecialchars($type['color'], ENT_QUOTES, 'UTF-8') . '"></span></td>';
        echo '<td>' . htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8')
            . ($type['is_default'] ? ' <span class="badge bg-secondary">' . __('Built-in') . '</span>' : '') . '</td>';
        echo '<td><i class="' . htmlspecialchars($type['icon'], ENT_QUOTES, 'UTF-8') . '"></i>'
            . ' <small class="text-muted">' . htmlspecialchars($type['icon'], ENT_QUOTES, 'UTF-8') . '</small></td>';
        echo '<td>' . (int)$type['sort_order'] . '</td>';
        echo '<td>' . ($type['is_active']
            ? '<span class="badge bg-success">' . __('Yes') . '</span>'
            : '<span class="badge bg-secondary">' . __('No') . '</span>') . '</td>';
        echo '<td class="text-end">';
        echo '<button class="btn btn-sm btn-outline-secondary me-1 am-edit-type-btn"'
            . ' data-id="' . $eid . '"'
            . ' data-name="' . htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-color="' . htmlspecialchars($type['color'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-icon="' . htmlspecialchars($type['icon'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-sort="' . (int)$type['sort_order'] . '"'
            . ' data-active="' . (int)$type['is_active'] . '"'
            . ' data-bs-toggle="modal" data-bs-target="#amEditTypeModal">'
            . '<i class="ti ti-pencil"></i></button>';
        if (!$type['is_default']) {
            echo '<form method="POST" action="" style="display:inline" onsubmit="return confirm(' . json_encode(__('Deactivate this type?', 'appointmentmanager')) . ')">';
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('action', ['value' => 'delete_type']);
            echo Html::hidden('id', ['value' => $eid]);
            echo '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>';
            echo '</form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    // Add modal
    echo '<div class="modal fade" id="amAddTypeModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST" action="">'
    . Html::hidden('_glpi_csrf_token', ['value' => $csrf])
    . Html::hidden('action', ['value' => 'add_type']) . '
    <div class="modal-header"><h5 class="modal-title">' . __('Add appointment type', 'appointmentmanager') . '</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">' . __('Name') . ' *</label>
        <input type="text" name="name" class="form-control" required maxlength="255"></div>
        <div class="mb-3"><label class="form-label">' . __('Color') . '</label>
        <input type="color" name="color" class="form-control form-control-color" value="#0055a4"></div>
        <div class="mb-3"><label class="form-label">' . __('Tabler icon class', 'appointmentmanager') . '</label>
        <input type="text" name="icon" class="form-control" maxlength="100" placeholder="ti ti-calendar"></div>
        <div class="mb-3"><label class="form-label">' . __('Sort order') . '</label>
        <input type="number" name="sort_order" class="form-control" value="0" min="0"></div>
        <div class="mb-3 form-check"><input type="checkbox" name="is_active" class="form-check-input" id="addTypeActive" checked>
        <label class="form-check-label" for="addTypeActive">' . __('Active') . '</label></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . __('Cancel') . '</button>
        <button type="submit" class="btn btn-primary">' . __('Save') . '</button>
    </div></form></div></div></div>';

    // Edit modal
    echo '<div class="modal fade" id="amEditTypeModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST" action="">'
    . Html::hidden('_glpi_csrf_token', ['value' => $csrf])
    . Html::hidden('action', ['value' => 'edit_type']) . '
    <input type="hidden" name="id" id="amEditTypeId">
    <div class="modal-header"><h5 class="modal-title">' . __('Edit appointment type', 'appointmentmanager') . '</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">' . __('Name') . ' *</label>
        <input type="text" name="name" id="amEditTypeName" class="form-control" required maxlength="255"></div>
        <div class="mb-3"><label class="form-label">' . __('Color') . '</label>
        <input type="color" name="color" id="amEditTypeColor" class="form-control form-control-color"></div>
        <div class="mb-3"><label class="form-label">' . __('Tabler icon class', 'appointmentmanager') . '</label>
        <input type="text" name="icon" id="amEditTypeIcon" class="form-control" maxlength="100"></div>
        <div class="mb-3"><label class="form-label">' . __('Sort order') . '</label>
        <input type="number" name="sort_order" id="amEditTypeSort" class="form-control" min="0"></div>
        <div class="mb-3 form-check"><input type="checkbox" name="is_active" class="form-check-input" id="amEditTypeActive">
        <label class="form-check-label" for="amEditTypeActive">' . __('Active') . '</label></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . __('Cancel') . '</button>
        <button type="submit" class="btn btn-primary">' . __('Save') . '</button>
    </div></form></div></div></div>
    <script>
    document.querySelectorAll(".am-edit-type-btn").forEach(function(btn){
        btn.addEventListener("click", function(){
            document.getElementById("amEditTypeId").value    = this.dataset.id;
            document.getElementById("amEditTypeName").value  = this.dataset.name;
            document.getElementById("amEditTypeColor").value = this.dataset.color;
            document.getElementById("amEditTypeIcon").value  = this.dataset.icon;
            document.getElementById("amEditTypeSort").value  = this.dataset.sort;
            document.getElementById("amEditTypeActive").checked = this.dataset.active === "1";
        });
    });
    </script>';
}

// ── Tab: Technicians ───────────────────────────────────────────────────────────
if ($tab === 'technicians') {
    $current_uid = (int)Session::getLoginUserID();

    if ($can_manage_techs) {
        $enrolled   = am_get_tech_users();
        $unenrolled = am_get_unenrolled_tech_users();

        echo '<div class="card"><div class="card-header d-flex justify-content-between align-items-center">';
        echo '<h5 class="mb-0">' . __('Enrolled Technicians', 'appointmentmanager') . '</h5>';
        if (!empty($unenrolled)) {
            echo '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#amAddTechModal">'
                . '<i class="ti ti-plus me-1"></i>' . __('Add technician', 'appointmentmanager') . '</button>';
        }
        echo '</div><div class="card-body">';

        if (empty($enrolled)) {
            echo '<p class="text-muted">' . __('No technicians enrolled yet. Add technicians whose availability should be managed.', 'appointmentmanager') . '</p>';
            if (!empty($unenrolled)) {
                echo '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#amAddTechModal">'
                    . '<i class="ti ti-plus me-1"></i>' . __('Add technician', 'appointmentmanager') . '</button>';
            }
        } else {
            echo '<table class="table table-hover mb-0"><thead><tr>'
                . '<th>' . __('Technician') . '</th><th></th>'
                . '</tr></thead><tbody>';
            foreach ($enrolled as $uid => $uname) {
                echo '<tr>';
                echo '<td class="align-middle">' . htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td class="text-end">';
                echo '<form method="POST" action="" style="display:inline" onsubmit="return confirm(' . json_encode(__('Remove this technician from availability management?', 'appointmentmanager')) . ')">';
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo Html::hidden('action', ['value' => 'unenroll_tech']);
                echo Html::hidden('users_id', ['value' => $uid]);
                echo '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>';
                echo '</form>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></div>';

        if (!empty($unenrolled)) {
            echo '<div class="modal fade" id="amAddTechModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">';
            echo '<form method="POST" action="">';
            echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
            echo Html::hidden('action', ['value' => 'enroll_tech']);
            echo '<div class="modal-header"><h5 class="modal-title">' . __('Add technician', 'appointmentmanager') . '</h5>';
            echo '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>';
            echo '<div class="modal-body">';
            echo '<div class="mb-3"><label class="form-label">' . __('Technician') . ' *</label>';
            echo '<select name="users_id" class="form-select" required>';
            echo '<option value="">' . __('Select a technician…', 'appointmentmanager') . '</option>';
            foreach ($unenrolled as $uid => $uname) {
                echo '<option value="' . (int)$uid . '">' . htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            echo '</select></div>';
            echo '</div>';
            echo '<div class="modal-footer">';
            echo '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . __('Cancel') . '</button>';
            echo '<button type="submit" class="btn btn-primary">' . __('Add') . '</button>';
            echo '</div></form></div></div></div>';
        }
    } else {
        $enrolled    = am_get_tech_users();
        $is_enrolled = isset($enrolled[$current_uid]);

        echo '<div class="card"><div class="card-header">';
        echo '<h5 class="mb-0">' . __('My Technician Enrollment', 'appointmentmanager') . '</h5>';
        echo '</div><div class="card-body">';
        if ($is_enrolled) {
            echo '<p class="text-success mb-3"><i class="ti ti-check me-1"></i>'
                . __('You are currently enrolled for availability management.', 'appointmentmanager') . '</p>';
            echo '<form method="POST" action="" onsubmit="return confirm(' . json_encode(__('Remove yourself from availability management?', 'appointmentmanager')) . ')">';
            echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
            echo Html::hidden('action', ['value' => 'unenroll_tech']);
            echo Html::hidden('users_id', ['value' => $current_uid]);
            echo '<button type="submit" class="btn btn-outline-danger"><i class="ti ti-user-minus me-1"></i>'
                . __('Unenroll myself', 'appointmentmanager') . '</button>';
            echo '</form>';
        } else {
            echo '<p class="text-muted mb-3">'
                . __('You are not enrolled. Enroll to make your availability visible for appointment scheduling.', 'appointmentmanager') . '</p>';
            echo '<form method="POST" action="">';
            echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
            echo Html::hidden('action', ['value' => 'enroll_tech']);
            echo Html::hidden('users_id', ['value' => $current_uid]);
            echo '<button type="submit" class="btn btn-primary"><i class="ti ti-user-plus me-1"></i>'
                . __('Enroll myself', 'appointmentmanager') . '</button>';
            echo '</form>';
        }
        echo '</div></div>';
    }
}

// ── Tab: Availability ──────────────────────────────────────────────────────────
if ($tab === 'availability') {
    $techs = $can_manage_all_avail ? am_get_tech_users() : [];

    if ($can_manage_all_avail && empty($techs)) {
        echo '<div class="alert alert-info">'
            . __('No technicians are enrolled yet.', 'appointmentmanager') . ' '
            . '<a href="' . htmlspecialchars($plugin_url . '/front/config.php?tab=technicians', ENT_QUOTES, 'UTF-8') . '">'
            . __('Add technicians in the Technicians tab.', 'appointmentmanager')
            . '</a></div>';
    } else {
        $first_enrolled    = $can_manage_all_avail && !empty($techs) ? array_key_first($techs) : null;
        $default_user      = $can_manage_all_avail ? ($first_enrolled ?? (int)Session::getLoginUserID()) : (int)Session::getLoginUserID();
        $selected_user     = $can_manage_all_avail ? (int)($_GET['users_id'] ?? $default_user) : (int)Session::getLoginUserID();

        echo '<div class="card"><div class="card-header"><h5 class="mb-0">'
            . __('Technician Weekly Availability', 'appointmentmanager') . '</h5></div><div class="card-body">';

    if ($can_manage_all_avail) {
        echo '<div class="mb-3"><label class="form-label">' . __('Technician') . '</label>';
        echo '<select class="form-select" style="max-width:300px" onchange="location.href=\''
            . htmlspecialchars($plugin_url . '/front/config.php?tab=availability&users_id=', ENT_QUOTES, 'UTF-8') . '\'+this.value">';
        foreach ($techs as $uid => $uname) {
            $sel = $uid === $selected_user ? ' selected' : '';
            echo '<option value="' . (int)$uid . '"' . $sel . '>' . htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select></div>';
    }

    $grid     = PluginAppointmentmanagerAvailability::getForUser($selected_user);
    $daynames = PluginAppointmentmanagerAvailability::getDayNames();

    echo '<form method="POST" action="">';
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
    echo Html::hidden('action', ['value' => 'save_all_days']);
    echo Html::hidden('users_id', ['value' => $selected_user]);
    echo '<table class="table"><thead><tr><th>' . __('Day') . '</th><th>' . __('Active') . '</th><th>' . __('From') . '</th><th>' . __('To') . '</th></tr></thead><tbody>';
    foreach ($grid as $day => $row) {
        $checked = $row['is_active'] ? ' checked' : '';
        echo '<tr>';
        echo '<td class="align-middle fw-semibold">' . htmlspecialchars($daynames[$day], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="align-middle"><input type="checkbox" name="is_active_' . $day . '" class="form-check-input"' . $checked . '></td>';
        echo '<td><input type="time" name="time_start_' . $day . '" class="form-control" style="width:120px" value="'
            . htmlspecialchars(substr($row['time_start'], 0, 5), ENT_QUOTES, 'UTF-8') . '"></td>';
        echo '<td><input type="time" name="time_end_' . $day . '" class="form-control" style="width:120px" value="'
            . htmlspecialchars(substr($row['time_end'], 0, 5), ENT_QUOTES, 'UTF-8') . '"></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>' . __('Save all', 'appointmentmanager') . '</button>';
    echo '</form>';
    echo '</div></div>';
    } // end else (enrolled techs exist)
}

// ── Tab: Blocked Periods ───────────────────────────────────────────────────────
if ($tab === 'blocked') {
    $techs = $can_manage_all_avail ? am_get_tech_users() : [];

    if ($can_manage_all_avail && empty($techs)) {
        echo '<div class="alert alert-info">'
            . __('No technicians are enrolled yet.', 'appointmentmanager') . ' '
            . '<a href="' . htmlspecialchars($plugin_url . '/front/config.php?tab=technicians', ENT_QUOTES, 'UTF-8') . '">'
            . __('Add technicians in the Technicians tab.', 'appointmentmanager')
            . '</a></div>';
    } else {
        $first_enrolled = $can_manage_all_avail && !empty($techs) ? array_key_first($techs) : null;
        $default_user   = $can_manage_all_avail ? ($first_enrolled ?? (int)Session::getLoginUserID()) : (int)Session::getLoginUserID();
        $selected_user  = $can_manage_all_avail ? (int)($_GET['users_id'] ?? $default_user) : (int)Session::getLoginUserID();

        echo '<div class="card"><div class="card-header d-flex justify-content-between align-items-center">';
        echo '<h5 class="mb-0">' . __('Blocked Periods', 'appointmentmanager') . '</h5>';
        echo '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#amAddBlockedModal">'
            . '<i class="ti ti-plus me-1"></i>' . __('Add blocked period', 'appointmentmanager') . '</button>';
        echo '</div><div class="card-body">';

        if ($can_manage_all_avail) {
            echo '<div class="mb-3"><label class="form-label">' . __('Technician') . '</label>';
            echo '<select class="form-select" style="max-width:300px" onchange="location.href=\''
                . htmlspecialchars($plugin_url . '/front/config.php?tab=blocked&users_id=', ENT_QUOTES, 'UTF-8') . '\'+this.value">';
            foreach ($techs as $uid => $uname) {
                $sel = $uid === $selected_user ? ' selected' : '';
                echo '<option value="' . (int)$uid . '"' . $sel . '>' . htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            echo '</select></div>';
        }

        $periods = PluginAppointmentmanagerBlockedPeriod::getForUser($selected_user);
        if (empty($periods)) {
            echo '<p class="text-muted">' . __('No blocked periods defined.', 'appointmentmanager') . '</p>';
        } else {
            echo '<table class="table table-hover"><thead><tr>'
                . '<th>' . __('Start') . '</th><th>' . __('End') . '</th><th>' . __('Reason') . '</th><th></th>'
                . '</tr></thead><tbody>';
            foreach ($periods as $p) {
                echo '<tr>';
                echo '<td>' . Html::convDateTime($p['date_start']) . '</td>';
                echo '<td>' . Html::convDateTime($p['date_end']) . '</td>';
                echo '<td>' . htmlspecialchars($p['reason'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td class="text-end">';
                echo '<form method="POST" action="" style="display:inline" onsubmit="return confirm(' . json_encode(__('Remove this blocked period?', 'appointmentmanager')) . ')">';
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo Html::hidden('action', ['value' => 'delete_blocked']);
                echo Html::hidden('id', ['value' => (int)$p['id']]);
                echo Html::hidden('users_id', ['value' => $selected_user]);
                echo '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>';
                echo '</form>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></div>';

        echo '<div class="modal fade" id="amAddBlockedModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST" action="">'
        . Html::hidden('_glpi_csrf_token', ['value' => $csrf])
        . Html::hidden('action', ['value' => 'add_blocked'])
        . Html::hidden('users_id', ['value' => $selected_user]) . '
    <div class="modal-header"><h5 class="modal-title">' . __('Add blocked period', 'appointmentmanager') . '</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">' . __('Start') . ' *</label>
        <input type="datetime-local" name="date_start" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">' . __('End') . ' *</label>
        <input type="datetime-local" name="date_end" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">' . __('Reason') . '</label>
        <input type="text" name="reason" class="form-control" maxlength="255"></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . __('Cancel') . '</button>
        <button type="submit" class="btn btn-primary">' . __('Save') . '</button>
    </div></form></div></div></div>';
    } // end else (enrolled techs exist)
}

// ── Tab: Integrations ──────────────────────────────────────────────────────────
if ($tab === 'integrations') {
    $current_user = (int)Session::getLoginUserID();
    $all_settings = PluginAppointmentmanagerOAuthProvider::getAllSettings();

    $providers = [
        'google'    => ['label' => 'Google Calendar',    'icon' => 'ti-brand-google'],
        'microsoft' => ['label' => 'Microsoft Outlook',  'icon' => 'ti-brand-windows'],
    ];

    // ── Admin: OAuth credentials ──────────────────────────────────────────────
    if ($is_admin) {
        echo '<div class="card mb-4">';
        echo '<div class="card-header"><h5 class="mb-0">' . __('OAuth credentials (admin)', 'appointmentmanager') . '</h5></div>';
        echo '<div class="card-body">';
        echo '<p class="text-muted small">'
            . __('Register an OAuth app with each provider and enter the credentials below. The redirect URI to register is:', 'appointmentmanager') . '<br>';
        $root = rtrim(Plugin::getWebDir('appointmentmanager', true), '/');
        echo '<code>' . htmlspecialchars(
            (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
            . str_replace(Plugin::getWebDir('appointmentmanager', true), '', $root)
            . '/plugins/appointmentmanager/front/oauth_callback.php?provider={provider}',
            ENT_QUOTES, 'UTF-8'
        ) . '</code></p>';

        echo '<form method="POST" action="">';
        echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
        echo Html::hidden('action', ['value' => 'save_oauth_settings']);
        echo '<div class="row g-3">';

        foreach ($providers as $prov_key => $prov_info) {
            $s = $all_settings[$prov_key] ?? [];
            echo '<div class="col-md-6"><div class="card border">';
            echo '<div class="card-header"><i class="ti ' . $prov_info['icon'] . ' me-1"></i>' . $prov_info['label'] . '</div>';
            echo '<div class="card-body">';
            echo '<div class="mb-2"><label class="form-label form-label-sm">Client ID</label>';
            echo '<input type="text" name="' . $prov_key . '_client_id" class="form-control form-control-sm"'
                . ' value="' . htmlspecialchars($s['client_id'] ?? '', ENT_QUOTES, 'UTF-8') . '"></div>';
            echo '<div class="mb-2"><label class="form-label form-label-sm">Client Secret</label>';
            echo '<input type="password" name="' . $prov_key . '_client_secret" class="form-control form-control-sm"'
                . ' value="' . htmlspecialchars($s['client_secret'] ?? '', ENT_QUOTES, 'UTF-8') . '"></div>';
            if ($prov_key === 'microsoft') {
                echo '<div class="mb-2"><label class="form-label form-label-sm">Tenant ID</label>';
                echo '<input type="text" name="microsoft_tenant_id" class="form-control form-control-sm"'
                    . ' value="' . htmlspecialchars($s['tenant_id'] ?? 'common', ENT_QUOTES, 'UTF-8') . '">';
                echo '<div class="form-text">' . __('Use "common" for multi-tenant apps.', 'appointmentmanager') . '</div></div>';
            }
            echo '</div></div></div>';
        }

        echo '</div>';
        echo '<div class="mt-3"><button type="submit" class="btn btn-primary btn-sm">'
            . '<i class="ti ti-device-floppy me-1"></i>' . __('Save credentials', 'appointmentmanager')
            . '</button></div>';
        echo '</form>';
        echo '</div></div>';

        // ── Backfill sync ─────────────────────────────────────────────────────
        echo '<div class="card mb-4">';
        echo '<div class="card-header"><h5 class="mb-0">' . __('Sync past appointments', 'appointmentmanager') . '</h5></div>';
        echo '<div class="card-body">';
        echo '<p class="text-muted small">'
            . __('Push existing appointments (proposed, confirmed, completed) to technicians\' and requesters\' connected calendars. Safe to run multiple times — already-synced appointments are updated, not duplicated.', 'appointmentmanager')
            . '</p>';
        echo '<form method="POST" action="" onsubmit="return confirm(' . json_encode(__('Sync all past appointments to connected calendars?', 'appointmentmanager')) . ')">';
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('action', ['value' => 'backfill_sync']);
        echo '<div class="d-flex align-items-end gap-3">';
        echo '<div><label class="form-label form-label-sm">' . __('Technician', 'appointmentmanager') . '</label>';
        echo '<select name="users_id_filter" class="form-select form-select-sm" style="min-width:200px">';
        echo '<option value="0">' . htmlspecialchars(__('All technicians', 'appointmentmanager'), ENT_QUOTES, 'UTF-8') . '</option>';
        foreach (am_get_tech_users() as $uid => $uname) {
            echo '<option value="' . (int)$uid . '">' . htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select></div>';
        echo '<button type="submit" class="btn btn-sm btn-outline-primary">'
            . '<i class="ti ti-cloud-upload me-1"></i>' . __('Sync now', 'appointmentmanager')
            . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div></div>';

        // ── Tech calendar connection status ───────────────────────────────────
        $enrolled_techs = am_get_tech_users();
        if (!empty($enrolled_techs)) {
            echo '<div class="card mb-4">';
            echo '<div class="card-header"><h5 class="mb-0">' . __('Technician calendar connections', 'appointmentmanager') . '</h5></div>';
            echo '<div class="card-body p-0">';
            echo '<table class="table table-hover mb-0"><thead><tr>'
                . '<th>' . __('Technician') . '</th>'
                . '<th>Google Calendar</th>'
                . '<th>Microsoft Outlook</th>'
                . '</tr></thead><tbody>';
            foreach ($enrolled_techs as $uid => $uname) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') . '</td>';
                foreach (['google', 'microsoft'] as $prov_key) {
                    $prov_settings = $all_settings[$prov_key] ?? null;
                    $is_enabled    = !empty($prov_settings['is_enabled']);
                    $token         = $is_enabled
                        ? PluginAppointmentmanagerOAuthProvider::getTokenRow((int)$uid, $prov_key)
                        : null;
                    echo '<td>';
                    if (!$is_enabled) {
                        echo '<span class="badge bg-secondary">' . __('Not configured', 'appointmentmanager') . '</span>';
                    } elseif ($token) {
                        echo '<span class="badge bg-success">' . __('Connected', 'appointmentmanager') . '</span>';
                    } else {
                        echo '<span class="badge bg-warning text-dark">' . __('Not connected', 'appointmentmanager') . '</span>';
                    }
                    echo '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div></div>';
        }
    }

    // ── Per-user: connect / disconnect ────────────────────────────────────────
    echo '<div class="card">';
    echo '<div class="card-header"><h5 class="mb-0">' . __('My calendar connections', 'appointmentmanager') . '</h5></div>';
    echo '<div class="card-body">';
    echo '<p class="text-muted small">'
        . __('Connect your calendar so appointments appear automatically and your existing events show as busy when proposing new times.', 'appointmentmanager')
        . '</p>';

    foreach ($providers as $prov_key => $prov_info) {
        $prov_settings = $all_settings[$prov_key] ?? null;
        $is_enabled    = !empty($prov_settings['is_enabled']);
        $token_row     = PluginAppointmentmanagerOAuthProvider::getTokenRow($current_user, $prov_key);
        $connected     = $token_row !== null;

        echo '<div class="d-flex align-items-center justify-content-between border rounded p-3 mb-3">';
        echo '<div><i class="ti ' . $prov_info['icon'] . ' fs-4 me-2"></i>'
            . '<strong>' . $prov_info['label'] . '</strong>';
        if ($connected) {
            echo ' <span class="badge bg-success ms-2">' . __('Connected', 'appointmentmanager') . '</span>';
        } elseif (!$is_enabled) {
            echo ' <span class="badge bg-secondary ms-2">' . __('Not configured', 'appointmentmanager') . '</span>';
        }
        echo '</div><div>';

        if ($connected) {
            echo '<form method="POST" action="" style="display:inline">';
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('action',   ['value' => 'disconnect_calendar']);
            echo Html::hidden('provider', ['value' => $prov_key]);
            echo '<button type="submit" class="btn btn-sm btn-outline-danger">'
                . '<i class="ti ti-unlink me-1"></i>' . __('Disconnect', 'appointmentmanager')
                . '</button>';
            echo '</form>';
        } elseif ($is_enabled) {
            echo '<a href="' . htmlspecialchars($plugin_url . '/front/oauth.php?provider=' . $prov_key, ENT_QUOTES, 'UTF-8') . '"'
                . ' class="btn btn-sm btn-outline-primary">'
                . '<i class="ti ti-plug me-1"></i>' . __('Connect', 'appointmentmanager')
                . '</a>';
        } else {
            echo '<span class="text-muted small">' . __('Contact admin to enable.', 'appointmentmanager') . '</span>';
        }

        echo '</div></div>';
    }

    echo '</div></div>';
}

echo '</div>'; // .container-fluid

Html::footer();
