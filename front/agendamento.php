<?php

use GlpiPlugin\Agendamento\Agendamento;

include '../../../inc/includes.php';

Session::checkLoginUser();
Html::requireJs('fullcalendar');

if (!Session::haveRight('plugin_agendamento', READ)) {
    Html::displayRightError();
    exit;
}

$anchorDate = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
$view = isset($_GET['view']) ? trim((string) $_GET['view']) : 'week';

if (isset($_POST['save_agendamento'])) {
    if (!Session::haveRight('plugin_agendamento', CREATE) && !Session::haveRight('plugin_agendamento', UPDATE)) {
        Html::displayRightError();
        exit;
    }

    $redirectDate = trim((string) ($_POST['date'] ?? $anchorDate));
    $redirectView = trim((string) ($_POST['view'] ?? $view));
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

    Html::redirect(Plugin::getWebDir('agendamento') . '/front/agendamento.php?date=' . rawurlencode($redirectDate) . '&view=' . rawurlencode($redirectView));
}

if (isset($_POST['update_agendamento_status'])) {
    if (!Session::haveRight('plugin_agendamento', UPDATE)) {
        Html::displayRightError();
        exit;
    }

    $redirectDate = trim((string) ($_POST['date'] ?? $anchorDate));
    $redirectView = trim((string) ($_POST['view'] ?? $view));

    try {
        Agendamento::updateStatus(
            (int) ($_POST['tickets_id'] ?? 0),
            (int) ($_POST['agendamento_id'] ?? 0),
            (string) ($_POST['update_agendamento_status'] ?? ''),
            (string) ($_POST['cancelamento_motivo'] ?? '')
        );
        Session::addMessageAfterRedirect(__('Status do agendamento atualizado com sucesso.', 'agendamento'), true, INFO);
    } catch (Throwable $e) {
        Session::addMessageAfterRedirect(__('Erro ao atualizar agendamento: ', 'agendamento') . $e->getMessage(), false, ERROR);
    }

    Html::redirect(Plugin::getWebDir('agendamento') . '/front/agendamento.php?date=' . rawurlencode($redirectDate) . '&view=' . rawurlencode($redirectView));
}

Html::header(
    __('Agendamento', 'agendamento'),
    $_SERVER['PHP_SELF'],
    'plugins',
    'GlpiPlugin\Agendamento\MenuAgendamento'
);

echo Html::css('lib/fullcalendar.css');

$inlineCss = @file_get_contents(Plugin::getPhpDir('agendamento') . '/public/css/agendamento.css');
if ($inlineCss !== false && trim($inlineCss) !== '') {
    echo "<style>\n" . $inlineCss . "\n</style>";
}

Agendamento::showOverview($anchorDate !== '' ? $anchorDate : null, $view);

Html::footer();