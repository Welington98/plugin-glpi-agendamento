<?php

define('PLUGIN_AGENDAMENTO_VERSION', '1.0.0');

function plugin_init_agendamento()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['agendamento'] = true;
    $PLUGIN_HOOKS['add_css']['agendamento'] = ['css/agendamento.css'];

    Plugin::registerClass('GlpiPlugin\\Agendamento\\MenuAgendamento');
    Plugin::registerClass('GlpiPlugin\\Agendamento\\Config', ['addtabon' => 'Config']);

    if (Session::getLoginUserID() && Session::haveRight('ticket', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['agendamento'] = [
            'plugins' => 'GlpiPlugin\\Agendamento\\MenuAgendamento',
        ];
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