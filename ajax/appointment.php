<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

$action = trim($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing id'], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

$appt = PluginAppointmentmanagerAppointment::getById($id);
if (!$appt) {
    echo json_encode(['success' => false, 'error' => 'Appointment not found'], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

$current_uid = (int)Session::getLoginUserID();
$is_admin    = Session::haveRight('plugin_appointmentmanager_appointment', UPDATE);
$is_tech     = PluginAppointmentmanagerAppointment::techOwns($id, $current_uid);

$redirect = trim($_POST['redirect'] ?? '');

if ($action === 'cancel') {
    if (!$is_admin && !$is_tech) {
        echo json_encode(['success' => false, 'error' => 'Permission denied'], JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    $ok = PluginAppointmentmanagerAppointment::updateStatus($id, PluginAppointmentmanagerAppointment::STATUS_CANCELLED, $current_uid);
    if ($ok) {
        PluginAppointmentmanagerAppointment::postFollowupForAction($id, (int)$appt['tickets_id'], PluginAppointmentmanagerAppointment::STATUS_CANCELLED, $current_uid);
    }
    if ($redirect) {
        Session::addMessageAfterRedirect(__('Appointment cancelled.', 'appointmentmanager'), false, INFO);
        Html::redirect($redirect);
    }
    echo json_encode(['success' => $ok], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

if ($action === 'complete') {
    if (!$is_admin && !$is_tech) {
        echo json_encode(['success' => false, 'error' => 'Permission denied'], JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    $ok = PluginAppointmentmanagerAppointment::updateStatus($id, PluginAppointmentmanagerAppointment::STATUS_COMPLETED, $current_uid);
    if ($ok) {
        PluginAppointmentmanagerAppointment::postFollowupForAction($id, (int)$appt['tickets_id'], PluginAppointmentmanagerAppointment::STATUS_COMPLETED, $current_uid);
    }
    if ($redirect) {
        Session::addMessageAfterRedirect(__('Appointment marked as completed.', 'appointmentmanager'), false, INFO);
        Html::redirect($redirect);
    }
    echo json_encode(['success' => $ok], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_HEX_TAG | JSON_HEX_AMP);
