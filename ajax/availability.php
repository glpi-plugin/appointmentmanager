<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

if (!Session::haveRight('plugin_appointmentmanager_availability', UPDATE)) {
    echo json_encode(['success' => false, 'error' => 'Permission denied'], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

$action      = trim($_POST['action'] ?? '');
$current_uid = (int)Session::getLoginUserID();
$is_admin    = Session::haveRight('plugin_appointmentmanager_appointment', UPDATE) || Session::haveRight('config', UPDATE);

if ($action === 'save_day') {
    $target_user = $is_admin ? (int)($_POST['users_id'] ?? $current_uid) : $current_uid;
    $day         = (int)($_POST['day_of_week'] ?? 0);
    $start       = trim($_POST['time_start'] ?? '08:00:00');
    $end         = trim($_POST['time_end']   ?? '18:00:00');
    $active      = !empty($_POST['is_active']);

    if ($day < 1 || $day > 7) {
        echo json_encode(['success' => false, 'error' => 'Invalid day'], JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    $ok = PluginAppointmentmanagerAvailability::saveDay($target_user, $day, $start, $end, $active);
    echo json_encode(['success' => $ok], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

if ($action === 'save_all') {
    $target_user = $is_admin ? (int)($_POST['users_id'] ?? $current_uid) : $current_uid;
    $days_input  = $_POST['days'] ?? [];
    if (!is_array($days_input)) {
        echo json_encode(['success' => false, 'error' => 'Invalid days'], JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    $ok = PluginAppointmentmanagerAvailability::saveAll($target_user, $days_input);
    echo json_encode(['success' => $ok], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

if ($action === 'add_blocked') {
    $target_user = $is_admin ? (int)($_POST['users_id'] ?? $current_uid) : $current_uid;
    $date_start  = trim($_POST['date_start'] ?? '');
    $date_end    = trim($_POST['date_end']   ?? '');
    $reason      = trim($_POST['reason']     ?? '');

    if (!$date_start || !$date_end || $date_start >= $date_end) {
        echo json_encode(['success' => false, 'error' => 'Invalid dates'], JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    $new_id = PluginAppointmentmanagerBlockedPeriod::create($target_user, $date_start, $date_end, $reason);
    echo json_encode(['success' => true, 'id' => $new_id], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

if ($action === 'delete_blocked') {
    $id = (int)($_POST['id'] ?? 0);
    $ok = PluginAppointmentmanagerBlockedPeriod::delete($id, $current_uid);
    echo json_encode(['success' => $ok], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_HEX_TAG | JSON_HEX_AMP);
