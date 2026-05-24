<?php
include('../../../inc/includes.php');

Session::checkLoginUser();

function am_get_tech_users(): array {
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

    $users = [];
    foreach ($iter as $row) {
        if (empty($row['id'])) {
            continue;
        }
        $display = trim($row['realname'] . ' ' . $row['firstname']);
        if ($display === '') {
            $display = $row['name'];
        }
        $users[(int)$row['id']] = $display;
    }
    return $users;
}

$tab          = $_GET['tab'] ?? 'types';
$allowed_tabs = ['types', 'availability', 'blocked', 'integrations'];
if (!in_array($tab, $allowed_tabs, true)) {
    $tab = 'types';
}

$plugin_url = Plugin::getWebDir('appointmentmanager', true);
$is_admin   = Session::haveRight('plugin_appointmentmanager_appointment', UPDATE) || Session::haveRight('config', UPDATE);
$csrf       = Session::getNewCSRFToken();

// Non-admins may only access the integrations tab
if ($tab !== 'integrations' && !$is_admin) {
    Html::displayRightError();
    exit;
}

// ── POST handlers ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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

    if ($action === 'save_all_days') {
        $target_user = $is_admin ? (int)($_POST['users_id'] ?? Session::getLoginUserID()) : (int)Session::getLoginUserID();
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
        $target_user = $is_admin ? (int)($_POST['users_id'] ?? Session::getLoginUserID()) : (int)Session::getLoginUserID();
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
        $target_user = $is_admin ? (int)($_POST['users_id'] ?? Session::getLoginUserID()) : (int)Session::getLoginUserID();
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
        $provider = trim($_POST['provider'] ?? '');
        if (in_array($provider, ['google', 'microsoft'], true)) {
            PluginAppointmentmanagerOAuthProvider::deleteToken((int)Session::getLoginUserID(), $provider);
            Session::addMessageAfterRedirect(__('Calendar disconnected.', 'appointmentmanager'), false, INFO);
        }
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
    'availability' => __('Technician Availability', 'appointmentmanager'),
    'blocked'      => __('Blocked Periods', 'appointmentmanager'),
    'integrations' => __('Calendar Integrations', 'appointmentmanager'),
];
foreach ($tab_labels as $key => $label) {
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

// ── Tab: Availability ──────────────────────────────────────────────────────────
if ($tab === 'availability') {
    $selected_user = $is_admin ? (int)($_GET['users_id'] ?? Session::getLoginUserID()) : (int)Session::getLoginUserID();

    echo '<div class="card"><div class="card-header"><h5 class="mb-0">'
        . __('Technician Weekly Availability', 'appointmentmanager') . '</h5></div><div class="card-body">';

    if ($is_admin) {
        $techs = am_get_tech_users();
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
}

// ── Tab: Blocked Periods ───────────────────────────────────────────────────────
if ($tab === 'blocked') {
    $selected_user = $is_admin ? (int)($_GET['users_id'] ?? Session::getLoginUserID()) : (int)Session::getLoginUserID();

    echo '<div class="card"><div class="card-header d-flex justify-content-between align-items-center">';
    echo '<h5 class="mb-0">' . __('Blocked Periods', 'appointmentmanager') . '</h5>';
    echo '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#amAddBlockedModal">'
        . '<i class="ti ti-plus me-1"></i>' . __('Add blocked period', 'appointmentmanager') . '</button>';
    echo '</div><div class="card-body">';

    if ($is_admin) {
        $techs = am_get_tech_users();
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

    // Add blocked period modal
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
