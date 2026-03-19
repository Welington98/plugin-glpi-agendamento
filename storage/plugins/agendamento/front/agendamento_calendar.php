<?php

use GlpiPlugin\Agendamento\Agendamento;

include '../../../inc/includes.php';

Session::checkLoginUser();

if (!Session::haveRight('ticket', READ)) {
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

        Agendamento::reschedule(
            (int) ($_POST['tickets_id'] ?? 0),
            (int) ($_POST['agendamento_id'] ?? 0),
            (string) ($_POST['start'] ?? ''),
            trim((string) ($_POST['end'] ?? '')) !== '' ? (string) $_POST['end'] : null
        );

        echo json_encode([
            'success' => true,
            'csrf_token' => Session::getNewCSRFToken(true),
        ]);
        exit;
    }

    if ($action === 'ticket_metadata') {
        $ticketId = (int) ($_GET['ticket_id'] ?? 0);
        if ($ticketId <= 0) {
            echo json_encode([]);
            exit;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            echo json_encode([]);
            exit;
        }

        // Fetch requester(s)
        $requesters = $ticket->getUsers(CommonITILActor::REQUESTER);
        $contact = '';
        $address = '';

        if (!empty($requesters)) {
            $firstRequester = reset($requesters);
            $userId = (int) ($firstRequester['users_id'] ?? 0);
            
            if ($userId > 0) {
                $user = new User();
                if ($user->getFromDB($userId)) {
                    // Try mobile first, then phone
                    $contact = trim((string) ($user->fields['mobile'] ?? ''));
                    if ($contact === '') {
                        $contact = trim((string) ($user->fields['phone'] ?? ''));
                    }
                    if ($contact === '') {
                        $contact = trim((string) ($user->fields['phone2'] ?? ''));
                    }
                    
                    // Construct address
                    $addressParts = [];
                    foreach (['address', 'town', 'state', 'country'] as $field) {
                        if (!empty($user->fields[$field])) {
                            $addressParts[] = $user->fields[$field];
                        }
                    }
                    $address = implode(', ', $addressParts);
                }
            }
        }

        // If ticket has specific location, use that? Usually plugin just wants user contact info.
        // Or if the ticket itself has location data (GLPI locations table).
        // For now, let's stick to the user logic which seems to be what getTicketMetadataMap does.
        
        echo json_encode([
            'contact' => $contact,
            'address' => $address,
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