<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

if (
    !Session::haveRight('plugin_appointmentmanager_type', UPDATE)
    && !Session::haveRight('config', UPDATE)
) {
    echo json_encode(['success' => false, 'error' => 'Permission denied'], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

$action = trim($_POST['action'] ?? '');

if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Name required'], JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    $id = PluginAppointmentmanagerAppointmentType::create([
        'name'       => $name,
        'color'      => $_POST['color'] ?? '#0055a4',
        'icon'       => $_POST['icon']  ?? '',
        'sort_order' => (int)($_POST['sort_order'] ?? 0),
        'is_active'  => !empty($_POST['is_active']) ? 1 : 0,
    ]);
    echo json_encode(['success' => true, 'id' => $id], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

if ($action === 'edit') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$id || $name === '') {
        echo json_encode(['success' => false, 'error' => 'ID and name required'], JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    $ok = PluginAppointmentmanagerAppointmentType::update($id, [
        'name'       => $name,
        'color'      => $_POST['color'] ?? '#0055a4',
        'icon'       => $_POST['icon']  ?? '',
        'sort_order' => (int)($_POST['sort_order'] ?? 0),
        'is_active'  => !empty($_POST['is_active']) ? 1 : 0,
    ]);
    echo json_encode(['success' => $ok], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

if ($action === 'delete') {
    $id  = (int)($_POST['id'] ?? 0);
    $row = PluginAppointmentmanagerAppointmentType::getById($id);
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Not found'], JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    if ($row['is_default']) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete built-in type'], JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    $ok = PluginAppointmentmanagerAppointmentType::delete($id);
    echo json_encode(['success' => $ok], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_HEX_TAG | JSON_HEX_AMP);
