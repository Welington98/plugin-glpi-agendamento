<?php

use Glpi\Exception\RedirectException;
use GlpiPlugin\Agendamento\GoogleCalendarAuth;
use GlpiPlugin\Agendamento\GoogleCalendarSync;

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkLoginUser();

if (!Session::haveRight('ticket', READ)) {
    Html::displayRightError();
    exit;
}

$action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
$userId = (int) Session::getLoginUserID();

try {
    switch ($action) {
        case 'connect':
            Session::checkCSRF($_GET);
            $url = GoogleCalendarAuth::getAuthorizationUrl();
            Html::redirect($url);
            break;

        case 'disconnect':
            Session::checkCSRF($_GET);
            GoogleCalendarAuth::revokeAccess($userId);
            Session::addMessageAfterRedirect(
                __('Google Calendar desconectado com sucesso.', 'agendamento'),
                false,
                INFO
            );
            Html::redirect($CFG_GLPI['root_doc'] . '/plugins/agendamento/front/meus_agendamentos.php');
            break;

        case 'sync':
            Session::checkCSRF($_GET);
            $result = GoogleCalendarSync::syncAllForTechnician($userId);
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Sincronização concluída: %d evento(s) sincronizado(s), %d erro(s).', 'agendamento'),
                    $result['synced'],
                    $result['errors']
                ),
                false,
                $result['errors'] > 0 ? WARNING : INFO
            );
            Html::redirect($CFG_GLPI['root_doc'] . '/plugins/agendamento/front/meus_agendamentos.php');
            break;

        default:
            Html::redirect($CFG_GLPI['root_doc'] . '/plugins/agendamento/front/meus_agendamentos.php');
    }
} catch (RedirectException $e) {
    throw $e;
} catch (\Throwable $e) {
    Toolbox::logInFile('agendamento', 'Google action error: ' . $e->getMessage());
    Session::addMessageAfterRedirect(
        htmlspecialchars($e->getMessage()),
        false,
        ERROR
    );
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/agendamento/front/meus_agendamentos.php');
}
