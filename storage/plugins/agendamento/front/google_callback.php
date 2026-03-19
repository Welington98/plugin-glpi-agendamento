<?php

use GlpiPlugin\Agendamento\GoogleCalendarAuth;

include '../../../inc/includes.php';

Session::checkLoginUser();

try {
    $code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
    $state = isset($_GET['state']) ? trim((string) $_GET['state']) : '';
    $error = isset($_GET['error']) ? trim((string) $_GET['error']) : '';

    if ($error !== '') {
        Session::addMessageAfterRedirect(
            sprintf(__('Erro na autorização Google: %s', 'agendamento'), htmlspecialchars($error)),
            false,
            ERROR
        );
        Html::redirect($CFG_GLPI['root_doc'] . '/plugins/agendamento/front/meus_agendamentos.php');
        return;
    }

    if ($code === '' || $state === '') {
        Session::addMessageAfterRedirect(
            __('Parâmetros de callback inválidos.', 'agendamento'),
            false,
            ERROR
        );
        Html::redirect($CFG_GLPI['root_doc'] . '/plugins/agendamento/front/meus_agendamentos.php');
        return;
    }

    GoogleCalendarAuth::handleCallback($code, $state);

    Session::addMessageAfterRedirect(
        __('Google Calendar conectado com sucesso!', 'agendamento'),
        false,
        INFO
    );
} catch (\Throwable $e) {
    Toolbox::logInFile('agendamento', 'Google OAuth callback error: ' . $e->getMessage());
    Session::addMessageAfterRedirect(
        sprintf(__('Erro ao conectar Google Calendar: %s', 'agendamento'), htmlspecialchars($e->getMessage())),
        false,
        ERROR
    );
}

Html::redirect($CFG_GLPI['root_doc'] . '/plugins/agendamento/front/meus_agendamentos.php');
