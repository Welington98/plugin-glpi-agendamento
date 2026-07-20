<?php

define('PLUGIN_AGENDAMENTO_VERSION', '1.5.1');

function plugin_agendamento_check_schema()
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_agendamento_agendamentos')) {
        return;
    }

    $defaultCharset = DBConnection::getDefaultCharset();
    $defaultCollation = DBConnection::getDefaultCollation();
    $defaultKeySign = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->fieldExists('glpi_plugin_agendamento_agendamentos', 'google_event_id')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_agendamento_agendamentos` ADD COLUMN `google_event_id` varchar(255) DEFAULT NULL");
    }

    if (!$DB->tableExists('glpi_plugin_agendamento_google_tokens')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_agendamento_google_tokens` (
            `id` int {$defaultKeySign} NOT NULL AUTO_INCREMENT,
            `users_id` int {$defaultKeySign} NOT NULL,
            `access_token` text DEFAULT NULL,
            `refresh_token` text DEFAULT NULL,
            `token_expiry` datetime DEFAULT NULL,
            `calendar_id` varchar(255) DEFAULT 'primary',
            `is_active` tinyint(1) DEFAULT 1,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$defaultCharset} COLLATE={$defaultCollation}");
    }

    if (!$DB->tableExists('glpi_plugin_agendamento_historico')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_agendamento_historico` (
            `id` int {$defaultKeySign} NOT NULL AUTO_INCREMENT,
            `agendamentos_id` int {$defaultKeySign} NOT NULL,
            `tickets_id` int {$defaultKeySign} NOT NULL DEFAULT 0,
            `users_id` int {$defaultKeySign} NOT NULL DEFAULT 0,
            `acao` varchar(50) NOT NULL,
            `descricao` text DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_plugin_agendamento_historico_agendamento` (`agendamentos_id`),
            KEY `idx_plugin_agendamento_historico_ticket` (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$defaultCharset} COLLATE={$defaultCollation}");
    }
}

function plugin_init_agendamento()
{
    global $PLUGIN_HOOKS, $DB;

    $PLUGIN_HOOKS['csrf_compliant']['agendamento'] = true;
    $PLUGIN_HOOKS['add_css']['agendamento'] = ['public/css/agendamento.css'];
    $PLUGIN_HOOKS['add_javascript']['agendamento'] = ['public/js/agendamento-ticket.js'];

    Plugin::registerClass('GlpiPlugin\\Agendamento\\MenuAgendamento');
    Plugin::registerClass('GlpiPlugin\\Agendamento\\Config', ['addtabon' => 'Config']);
    Plugin::registerClass('GlpiPlugin\\Agendamento\\GoogleCalendarAuth');
    Plugin::registerClass('GlpiPlugin\\Agendamento\\GoogleCalendarSync');
    Plugin::registerClass('GlpiPlugin\\Agendamento\\Profile', ['addtabon' => 'Profile']);

    if (isset($DB) && $DB->connected) {
        plugin_agendamento_check_schema();

        $profileRight = new ProfileRight();
        $hasRights = $profileRight->find(['name' => 'plugin_agendamento'], [], 1);
        if (empty($hasRights)) {
            \GlpiPlugin\Agendamento\Profile::installRights();
        }
    }

    if (Session::getLoginUserID() && Session::haveRight('plugin_agendamento', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['agendamento'] = [
            'plugins' => 'GlpiPlugin\\Agendamento\\MenuAgendamento',
        ];

        $PLUGIN_HOOKS[Glpi\Plugin\Hooks::DISPLAY_CENTRAL]['agendamento'] = 'plugin_agendamento_display_central';
        $PLUGIN_HOOKS[Glpi\Plugin\Hooks::TIMELINE_ACTIONS]['agendamento'] = 'plugin_agendamento_timeline_actions';
        $PLUGIN_HOOKS[Glpi\Plugin\Hooks::POST_SHOW_ITEM]['agendamento'] = 'plugin_agendamento_post_show_item';
    }
}

function plugin_version_agendamento()
{
    return [
        'name'           => __('Agendamento', 'agendamento'),
        'version'        => PLUGIN_AGENDAMENTO_VERSION,
        'author'         => 'Welington Oliveira',
        'license'        => 'GPL-3.0-or-later',
        'homepage'       => 'https://github.com/Welington98/plugin-glpi-gestaoclick',
        'minGlpiVersion' => '11.0.0',
        'requirements'   => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.9.99',
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_agendamento_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, '11.0.0', 'lt')) {
        echo sprintf(__('This plugin requires GLPI >= %s', 'agendamento'), '11.0.0');
        return false;
    }

    if (version_compare(PHP_VERSION, '8.2', 'lt')) {
        echo sprintf(__('This plugin requires PHP >= %s', 'agendamento'), '8.2');
        return false;
    }

    return true;
}

function plugin_agendamento_check_config()
{
    return true;
}

function plugin_agendamento_timeline_actions(array $params): void
{
    if (!Session::haveRight('plugin_agendamento', CREATE)) {
        return;
    }

    $item = $params['item'] ?? null;
    if (!($item instanceof Ticket)) {
        return;
    }

    $ticketId = (int) $item->getID();
    if ($ticketId <= 0) {
        return;
    }

    echo "<li>";
    echo "<a class='btn btn-outline-warning answer-action plugin-agendamento-ticket-action' href='#' data-bs-toggle='modal' data-bs-target='#plugin-agendamento-ticket-modal' data-open-modal='plugin-agendamento-ticket-modal' data-ticket-id='" . $ticketId . "'>";
    echo "<i class='ti ti-calendar-plus me-1'></i>";
    echo "<span>" . htmlescape(__('Criar agendamento', 'agendamento')) . "</span>";
    echo "</a>";
    echo "</li>";
}

function plugin_agendamento_post_show_item(array $params): void
{
    if (!Session::haveRight('plugin_agendamento', CREATE)) {
        return;
    }

    $item = $params['item'] ?? null;
    if (!($item instanceof Ticket)) {
        return;
    }

    \GlpiPlugin\Agendamento\Agendamento::renderTicketCreateModal($item);
}