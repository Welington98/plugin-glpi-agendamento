<?php

use GlpiPlugin\Agendamento\Agendamento;

include '../../../inc/includes.php';

Session::checkLoginUser();

if (!Session::haveRight('plugin_agendamento', READ)) {
    Html::displayRightError();
    exit;
}

Html::requireJs('fullcalendar');

Html::header(
    __('Meus Agendamentos', 'agendamento'),
    $_SERVER['PHP_SELF'],
    'plugins',
    'GlpiPlugin\Agendamento\MenuAgendamento',
    'meus'
);

echo Html::css('lib/fullcalendar.css');

Agendamento::showMeusAgendamentos();

Html::footer();
