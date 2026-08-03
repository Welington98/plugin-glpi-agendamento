<?php

namespace GlpiPlugin\Agendamento;

use CommonDBTM;

class AgendamentoItem extends CommonDBTM
{
    public static $rightname = 'plugin_agendamento';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_agendamento_agendamentos';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Agendamento', 'agendamento');
    }
}
