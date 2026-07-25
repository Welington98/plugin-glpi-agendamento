<?php

namespace GlpiPlugin\Agendamento;

use CommonDBTM;
use CommonGLPI;
use Session;

class TicketAgendamento extends CommonDBTM
{
    public static $rightname = 'plugin_agendamento';
    protected static $notable = true;

    public static function getTypeName($nb = 0)
    {
        return __('Agendamentos', 'agendamento');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($withtemplate || !($item instanceof \Ticket) || !$item->getID()) {
            return '';
        }

        if (!Session::haveRight('plugin_agendamento', READ)) {
            return '';
        }

        $count = count(Agendamento::getForTicket((int) $item->getID()));

        return self::createTabEntry(__('Agendamentos', 'agendamento'), $count, $item::class, 'ti ti-calendar-event');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Ticket) {
            Agendamento::renderTicketTab($item);
        }
        return true;
    }
}
