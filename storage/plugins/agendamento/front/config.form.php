<?php

use GlpiPlugin\Agendamento\Config;

include '../../../inc/includes.php';

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

if (!empty($_POST) && isset($_POST['update_config'])) {
    Config::processForm($_POST);
}

Html::back();
