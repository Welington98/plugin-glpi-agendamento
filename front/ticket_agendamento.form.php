<?php

use GlpiPlugin\Agendamento\Agendamento;

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkLoginUser();

if (!Session::haveRightsOr('plugin_agendamento', [CREATE, UPDATE, READ])) {
    Html::displayRightError();
    exit;
}

$ticketId = (int) ($_POST['ticket_redirect_id'] ?? $_POST['agendamento_tickets_id'] ?? $_POST['tickets_id'] ?? 0);
$redirectUrl = $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $ticketId . '&forcetab=GlpiPlugin\Agendamento\TicketAgendamento$1';

if (isset($_POST['update_agendamento_status'])) {
    if (!Session::haveRight('plugin_agendamento', UPDATE)) {
        Html::displayRightError();
        exit;
    }

    try {
        Agendamento::updateStatus(
            $ticketId,
            (int) ($_POST['agendamento_id'] ?? 0),
            (string) ($_POST['update_agendamento_status'] ?? ''),
            (string) ($_POST['cancelamento_motivo'] ?? '')
        );
        Session::addMessageAfterRedirect(__('Status do agendamento atualizado com sucesso.', 'agendamento'), true, INFO);
    } catch (Throwable $e) {
        Session::addMessageAfterRedirect(__('Erro ao atualizar agendamento: ', 'agendamento') . $e->getMessage(), false, ERROR);
    }

    Html::redirect($redirectUrl);
}

if (isset($_POST['save_agendamento'])) {
    $action = trim((string) ($_POST['agendamento_action'] ?? 'create'));

    if ($action === 'edit' && !Session::haveRight('plugin_agendamento', UPDATE)) {
        Html::displayRightError();
        exit;
    }

    if ($action !== 'edit' && !Session::haveRight('plugin_agendamento', CREATE)) {
        Html::displayRightError();
        exit;
    }

    try {
        if ($action === 'edit') {
            Agendamento::updateFromForm($_POST);
            Session::addMessageAfterRedirect(__('Agendamento atualizado com sucesso.', 'agendamento'), true, INFO);
        } else {
            Agendamento::createFromForm($_POST);
            Session::addMessageAfterRedirect(__('Agendamento registrado com sucesso.', 'agendamento'), true, INFO);
        }
    } catch (Throwable $e) {
        Session::addMessageAfterRedirect(__('Erro ao salvar agendamento: ', 'agendamento') . $e->getMessage(), false, ERROR);
    }

    Html::redirect($redirectUrl);
}

Html::displayErrorAndDie('lost');
