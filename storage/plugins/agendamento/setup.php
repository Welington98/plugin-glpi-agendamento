<?php

define('PLUGIN_AGENDAMENTO_VERSION', '1.0.0');

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
}

function plugin_init_agendamento()
{
    global $PLUGIN_HOOKS, $DB;

    $PLUGIN_HOOKS['csrf_compliant']['agendamento'] = true;
    $PLUGIN_HOOKS['add_css']['agendamento'] = ['css/agendamento.css'];

    Plugin::registerClass('GlpiPlugin\\Agendamento\\MenuAgendamento');
    Plugin::registerClass('GlpiPlugin\\Agendamento\\Config', ['addtabon' => 'Config']);
    Plugin::registerClass('GlpiPlugin\\Agendamento\\GoogleCalendarAuth');
    Plugin::registerClass('GlpiPlugin\\Agendamento\\GoogleCalendarSync');

    if (isset($DB) && $DB->connected) {
        plugin_agendamento_check_schema();
    }

    if (Session::getLoginUserID() && Session::haveRight('ticket', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['agendamento'] = [
            'plugins' => 'GlpiPlugin\\Agendamento\\MenuAgendamento',
        ];

        $PLUGIN_HOOKS[Glpi\Plugin\Hooks::DISPLAY_CENTRAL]['agendamento'] = 'plugin_agendamento_display_central';
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