<?php

namespace GlpiPlugin\Agendamento;

use Session;

class MenuAgendamento extends \CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Agendamento', 'agendamento');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-time';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight('ticket', READ)) {
            return false;
        }

        // Técnicos (sem direito de CREATE/UPDATE no plugin) só gerenciam os
        // próprios agendamentos, então o menu principal já abre direto em
        // "Meus Agendamentos" em vez da agenda geral de todos os técnicos.
        $defaultPage = Session::haveRightsOr('plugin_agendamento', [CREATE, UPDATE])
            ? '/plugins/agendamento/front/agendamento.php'
            : '/plugins/agendamento/front/meus_agendamentos.php';

        $menu = [
            'title' => __('Agendamento', 'agendamento'),
            'page'  => $defaultPage,
            'icon'  => self::getIcon(),
            'options' => [
                'agenda' => [
                    'title' => __('Agenda Geral', 'agendamento'),
                    'page'  => '/plugins/agendamento/front/agendamento.php',
                    'icon'  => 'ti ti-calendar-event',
                ],
                'meus' => [
                    'title' => __('Meus Agendamentos', 'agendamento'),
                    'page'  => '/plugins/agendamento/front/meus_agendamentos.php',
                    'icon'  => 'ti ti-calendar-user',
                ],
            ],
        ];

        if (Session::haveRight('config', READ)) {
            $menu['options']['config'] = [
                'title' => __('Configuração', 'agendamento'),
                'page'  => '/plugins/agendamento/front/config.php',
                'icon'  => 'ti ti-settings',
            ];
        }

        return $menu;
    }
}