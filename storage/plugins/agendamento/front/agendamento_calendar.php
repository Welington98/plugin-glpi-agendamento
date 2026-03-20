<?php

use GlpiPlugin\Agendamento\Agendamento;

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkLoginUser();

if (!Session::haveRight('plugin_agendamento', READ)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => __('Acesso negado.', 'agendamento'),
    ]);
    exit;
}

$action = strtolower(trim((string) ($_REQUEST['action'] ?? 'events')));

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($action === 'reschedule') {
        Session::checkCSRF($_POST);

        $motivo = trim((string) ($_POST['motivo'] ?? ''));

        Agendamento::reschedule(
            (int) ($_POST['tickets_id'] ?? 0),
            (int) ($_POST['agendamento_id'] ?? 0),
            (string) ($_POST['start'] ?? ''),
            trim((string) ($_POST['end'] ?? '')) !== '' ? (string) $_POST['end'] : null,
            $motivo !== '' ? $motivo : null
        );

        echo json_encode([
            'success' => true,
            'csrf_token' => Session::getNewCSRFToken(true),
        ]);
        exit;
    }

    if ($action === 'ticket_metadata') {
        $ticketId = (int) ($_GET['ticket_id'] ?? 0);
        echo json_encode(Agendamento::getTicketMetadata($ticketId));
        exit;
    }

    if ($action === 'available_slots') {
        if (!Session::haveRight('plugin_agendamento', CREATE) && !Session::haveRight('plugin_agendamento', UPDATE)) {
            throw new RuntimeException(__('Acesso negado.', 'agendamento'));
        }

        echo json_encode([
            'success' => true,
            'slots' => Agendamento::findAvailableSlots(
                (int) ($_GET['tech_id'] ?? 0),
                (string) ($_GET['date'] ?? ''),
                (int) ($_GET['duration'] ?? 60)
            ),
        ]);
        exit;
    }

    $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
    $techId = isset($_GET['tech_id']) ? (int) $_GET['tech_id'] : 0;
    echo json_encode(Agendamento::getCalendarEvents((string) ($_GET['start'] ?? ''), (string) ($_GET['end'] ?? ''), $rootDoc, $techId > 0 ? $techId : null));
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}