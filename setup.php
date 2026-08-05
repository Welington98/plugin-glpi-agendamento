<?php

define('PLUGIN_AGENDAMENTO_VERSION', '1.15.0');

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
    if (!$DB->fieldExists('glpi_plugin_agendamento_agendamentos', 'tipo')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_agendamento_agendamentos` ADD COLUMN `tipo` varchar(100) DEFAULT NULL AFTER `status`");
    }
    if (!$DB->fieldExists('glpi_plugin_agendamento_agendamentos', 'reminder_sent')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_agendamento_agendamentos` ADD COLUMN `reminder_sent` datetime DEFAULT NULL");
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

function plugin_agendamento_seed_default_notifications()
{
    global $DB;

    if (!$DB->tableExists('glpi_notifications') || !$DB->tableExists('glpi_notificationtemplates')) {
        return;
    }

    $itemtype = \GlpiPlugin\Agendamento\AgendamentoItem::class;
    // Must match GlpiPlugin\Agendamento\NotificationTargetAgendamentoItem::TARGET_TICKET_REQUESTER
    $requesterTargetId = 900001;

    $events = [
        'new' => [
            'label' => __('Agendamento criado', 'agendamento'),
            'subject' => __('Novo agendamento criado - Chamado ##agendamento.ticket_id##', 'agendamento'),
            'content' => __("Um novo agendamento foi criado.\n\nChamado: ##agendamento.ticket_id##\nTécnico: ##agendamento.technician##\nInício: ##agendamento.date_start##\nFim: ##agendamento.date_end##\nStatus: ##agendamento.status##\n\nAcesse o chamado: ##agendamento.url##", 'agendamento'),
        ],
        'update' => [
            'label' => __('Agendamento atualizado/reagendado', 'agendamento'),
            'subject' => __('Agendamento atualizado - Chamado ##agendamento.ticket_id##', 'agendamento'),
            'content' => __("O agendamento do chamado ##agendamento.ticket_id## foi atualizado.\n\nTécnico: ##agendamento.technician##\nNovo início: ##agendamento.date_start##\nNovo fim: ##agendamento.date_end##\nStatus: ##agendamento.status##\nMotivo do reagendamento: ##agendamento.reschedule_reason##\n\nAcesse o chamado: ##agendamento.url##", 'agendamento'),
        ],
        'cancel' => [
            'label' => __('Agendamento cancelado', 'agendamento'),
            'subject' => __('Agendamento cancelado - Chamado ##agendamento.ticket_id##', 'agendamento'),
            'content' => __("O agendamento do chamado ##agendamento.ticket_id## foi cancelado.\n\nTécnico: ##agendamento.technician##\nData que estava agendada: ##agendamento.date_start##\nMotivo do cancelamento: ##agendamento.cancel_reason##\n\nAcesse o chamado: ##agendamento.url##", 'agendamento'),
        ],
        'reminder' => [
            'label' => __('Lembrete de agendamento', 'agendamento'),
            'subject' => __('Lembrete: agendamento em breve - Chamado ##agendamento.ticket_id##', 'agendamento'),
            'content' => __("Este é um lembrete do seu agendamento.\n\nChamado: ##agendamento.ticket_id##\nTécnico: ##agendamento.technician##\nInício: ##agendamento.date_start##\nFim: ##agendamento.date_end##\n\nAcesse o chamado: ##agendamento.url##", 'agendamento'),
        ],
    ];

    foreach ($events as $event => $data) {
        if (countElementsInTable('glpi_notifications', ['itemtype' => $itemtype, 'event' => $event]) > 0) {
            continue;
        }

        $name = sprintf(__('Agendamento - %s', 'agendamento'), $data['label']);

        $DB->insert('glpi_notifications', [
            'name' => $name,
            'entities_id' => 0,
            'itemtype' => $itemtype,
            'event' => $event,
            'comment' => '',
            'is_recursive' => 1,
            'is_active' => 1,
            'date_creation' => new \Glpi\DBAL\QueryExpression('NOW()'),
            'date_mod' => new \Glpi\DBAL\QueryExpression('NOW()'),
        ]);
        $notificationId = $DB->insertId();

        $DB->insert('glpi_notificationtemplates', [
            'name' => $name,
            'itemtype' => $itemtype,
            'date_creation' => new \Glpi\DBAL\QueryExpression('NOW()'),
            'date_mod' => new \Glpi\DBAL\QueryExpression('NOW()'),
        ]);
        $templateId = $DB->insertId();

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templateId,
            'language' => '',
            'subject' => $data['subject'],
            'content_text' => $data['content'],
            'content_html' => '<p>' . nl2br(htmlspecialchars($data['content'])) . '</p>',
        ]);

        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id' => $notificationId,
            'mode' => Notification_NotificationTemplate::MODE_MAIL,
            'notificationtemplates_id' => $templateId,
        ]);

        $DB->insert('glpi_notificationtargets', [
            'items_id' => Notification::ITEM_TECH_IN_CHARGE,
            'type' => Notification::USER_TYPE,
            'notifications_id' => $notificationId,
        ]);

        $DB->insert('glpi_notificationtargets', [
            'items_id' => $requesterTargetId,
            'type' => Notification::USER_TYPE,
            'notifications_id' => $notificationId,
        ]);
    }
}

function plugin_init_agendamento()
{
    global $PLUGIN_HOOKS, $DB;

    $PLUGIN_HOOKS['csrf_compliant']['agendamento'] = true;
    $PLUGIN_HOOKS['add_css']['agendamento'] = ['public/css/agendamento.css'];
    $PLUGIN_HOOKS['add_javascript']['agendamento'] = ['public/js/agendamento-ticket.js', 'public/js/agendamento-ticket-tab.js'];
    $PLUGIN_HOOKS[Glpi\Plugin\Hooks::ITEM_UPDATE]['agendamento'] = [
        'Ticket' => 'plugin_agendamento_ticket_status_updated',
    ];

    Plugin::registerClass('GlpiPlugin\\Agendamento\\MenuAgendamento');
    Plugin::registerClass('GlpiPlugin\\Agendamento\\Config', ['addtabon' => 'Config']);
    Plugin::registerClass('GlpiPlugin\\Agendamento\\GoogleCalendarAuth');
    Plugin::registerClass('GlpiPlugin\\Agendamento\\GoogleCalendarSync');
    Plugin::registerClass('GlpiPlugin\\Agendamento\\Profile', ['addtabon' => 'Profile']);
    Plugin::registerClass('GlpiPlugin\\Agendamento\\TicketAgendamento', ['addtabon' => 'Ticket']);
    Plugin::registerClass('GlpiPlugin\\Agendamento\\AgendamentoItem', ['notificationtemplates_types' => true]);

    if (isset($DB) && $DB->connected) {
        plugin_agendamento_check_schema();
        plugin_agendamento_seed_default_notifications();

        $profileRight = new ProfileRight();
        $hasRights = $profileRight->find(['name' => 'plugin_agendamento'], [], 1);
        if (empty($hasRights)) {
            \GlpiPlugin\Agendamento\Profile::installRights();
        }

        CronTask::register(
            'GlpiPlugin\\Agendamento\\CronTaskAgendamento',
            \GlpiPlugin\Agendamento\CronTaskAgendamento::TASK_NAME,
            HOUR_TIMESTAMP,
            [
                'comment' => __('Envia lembretes de agendamentos futuros', 'agendamento'),
                'mode' => CronTask::MODE_EXTERNAL,
            ]
        );
    }

    if (Session::getLoginUserID() && Session::haveRight('plugin_agendamento', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['agendamento'] = [
            'plugins' => 'GlpiPlugin\\Agendamento\\MenuAgendamento',
        ];

        $PLUGIN_HOOKS[Glpi\Plugin\Hooks::DISPLAY_CENTRAL]['agendamento'] = 'plugin_agendamento_display_central';
        $PLUGIN_HOOKS[Glpi\Plugin\Hooks::TIMELINE_ACTIONS]['agendamento'] = 'plugin_agendamento_timeline_actions';
        $PLUGIN_HOOKS[Glpi\Plugin\Hooks::POST_SHOW_ITEM]['agendamento'] = 'plugin_agendamento_post_show_item';
        $PLUGIN_HOOKS[Glpi\Plugin\Hooks::DASHBOARD_CARDS]['agendamento'] = 'plugin_agendamento_dashboard_cards';
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