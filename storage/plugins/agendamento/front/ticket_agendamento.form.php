<?php

use GlpiPlugin\Agendamento\Agendamento;

include '../../../inc/includes.php';

Session::checkLoginUser();

if (!Session::haveRightsOr('plugin_agendamento', [CREATE, UPDATE, READ])) {
    Html::displayRightError();
    exit;
}

if (!isset($_POST['save_agendamento'])) {
    Html::displayErrorAndDie('lost');
}

$ticketId = (int) ($_POST['ticket_redirect_id'] ?? $_POST['agendamento_tickets_id'] ?? 0);

try {
    Agendamento::createFromForm($_POST);
    Session::addMessageAfterRedirect(__('Agendamento registrado com sucesso.', 'agendamento'), true, INFO);
} catch (Throwable $e) {
    Session::addMessageAfterRedirect(__('Erro ao salvar agendamento: ', 'agendamento') . $e->getMessage(), false, ERROR);
}

Html::redirect($CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $ticketId);
