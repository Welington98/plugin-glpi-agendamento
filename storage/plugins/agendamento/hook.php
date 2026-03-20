<?php

function plugin_agendamento_install()
{
    global $DB;

    $version = plugin_version_agendamento();
    $migration = new Migration($version['version']);

    plugin_agendamento_install_tables($migration);

    $migration->executeMigration();

    GlpiPlugin\Agendamento\Profile::installRights();

    return true;
}

function plugin_agendamento_display_central()
{
    GlpiPlugin\Agendamento\Agendamento::showCentralWidget();
}

function plugin_agendamento_uninstall()
{
    global $DB;

    GlpiPlugin\Agendamento\Profile::uninstallRights();

    if ($DB->tableExists('glpi_plugin_agendamento_google_tokens')) {
        $DB->dropTable('glpi_plugin_agendamento_google_tokens');
    }

    return true;
}

function plugin_agendamento_install_tables(Migration $migration)
{
    global $DB;

    $defaultCharset = DBConnection::getDefaultCharset();
    $defaultCollation = DBConnection::getDefaultCollation();
    $defaultKeySign = DBConnection::getDefaultPrimaryKeySignOption();

    if ($DB->tableExists('glpi_plugin_agendamento_agendamentos')) {
        if (!$DB->fieldExists('glpi_plugin_agendamento_agendamentos', 'contato_cliente')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_agendamento_agendamentos` ADD COLUMN `contato_cliente` varchar(255) DEFAULT NULL AFTER `tecnico_nome`");
        }
        if (!$DB->fieldExists('glpi_plugin_agendamento_agendamentos', 'endereco_cliente')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_agendamento_agendamentos` ADD COLUMN `endereco_cliente` text DEFAULT NULL AFTER `contato_cliente`");
        }
        if (!$DB->fieldExists('glpi_plugin_agendamento_agendamentos', 'google_event_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_agendamento_agendamentos` ADD COLUMN `google_event_id` varchar(255) DEFAULT NULL");
        }
        if (!$DB->fieldExists('glpi_plugin_agendamento_agendamentos', 'motivo_reagendamento')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_agendamento_agendamentos` ADD COLUMN `motivo_reagendamento` text DEFAULT NULL");
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
        return;
    }

    $DB->doQuery("CREATE TABLE `glpi_plugin_agendamento_agendamentos` (
        `id` int {$defaultKeySign} NOT NULL AUTO_INCREMENT,
        `tickets_id` int {$defaultKeySign} NOT NULL DEFAULT 0,
        `users_id_tech` int {$defaultKeySign} NOT NULL DEFAULT 0,
        `tecnico_nome` varchar(255) DEFAULT NULL,
        `contato_cliente` varchar(255) DEFAULT NULL,
        `endereco_cliente` text DEFAULT NULL,
        `data_hora_inicio` datetime NOT NULL,
        `data_hora_fim` datetime DEFAULT NULL,
        `status` varchar(50) NOT NULL DEFAULT 'agendado',
        `observacoes` text DEFAULT NULL,
        `users_id` int {$defaultKeySign} NOT NULL DEFAULT 0,
        `tickettasks_id` int {$defaultKeySign} NOT NULL DEFAULT 0,
        `google_event_id` varchar(255) DEFAULT NULL,
        `motivo_reagendamento` text DEFAULT NULL,
        `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_plugin_agendamento_ticket` (`tickets_id`),
        KEY `idx_plugin_agendamento_inicio` (`data_hora_inicio`),
        KEY `idx_plugin_agendamento_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$defaultCharset} COLLATE={$defaultCollation}");

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