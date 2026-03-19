<?php

use GlpiPlugin\Agendamento\Config;

include '../../../inc/includes.php';

Session::checkLoginUser();
Session::checkRight('config', READ);

Html::header(
    __('Agendamento - Configuração', 'agendamento'),
    $_SERVER['PHP_SELF'],
    'plugins',
    'GlpiPlugin\Agendamento\MenuAgendamento',
    'config'
);

$config = new Config();
$config->showConfigForm();

Html::footer();
