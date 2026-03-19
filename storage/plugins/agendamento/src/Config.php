<?php

namespace GlpiPlugin\Agendamento;

use CommonDBTM;
use CommonGLPI;
use Config as GlpiConfig;
use Dropdown;
use Html;
use Session;
use Toolbox;

class Config extends CommonDBTM
{
    public static $rightname = 'config';
    protected static $notable = true;

    private const CONTEXT = 'plugin:agendamento';

    private const DEFAULTS = [
        'default_view' => 'week',
        'slot_min_time' => '07:00',
        'slot_max_time' => '21:00',
        'slot_duration' => '00:30:00',
        'default_event_duration' => 60,
        'auto_create_task' => 1,
        'notify_technician' => 0,
        'calendar_height' => 650,
        'business_days' => '1,2,3,4,5',
    ];

    public static function getTypeName($nb = 0)
    {
        return __('Configuração Agendamento', 'agendamento');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item->getType() === 'Config') {
            return self::getTypeName();
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === 'Config') {
            $config = new self();
            $config->showConfigForm();
        }
        return true;
    }

    public static function getConfig(): array
    {
        $stored = GlpiConfig::getConfigurationValues(self::CONTEXT);
        return array_merge(self::DEFAULTS, $stored);
    }

    public static function getConfigValue(string $key, mixed $default = null): mixed
    {
        $config = self::getConfig();
        return $config[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function showConfigForm(): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            return;
        }

        $config = self::getConfig();
        $pluginWebDir = \Plugin::getWebDir('agendamento');

        echo "<div class='center'>";
        echo "<form method='post' action='" . htmlspecialchars($pluginWebDir) . "/front/config.form.php'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'><i class='ti ti-calendar-event me-1'></i>" . __('Configurações do Calendário', 'agendamento') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='default_view'>" . __('Visualização Padrão', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showFromArray('default_view', [
            'day' => __('Diário', 'agendamento'),
            'week' => __('Semanal', 'agendamento'),
            'month' => __('Mensal', 'agendamento'),
        ], ['value' => $config['default_view'], 'display' => true, 'width' => '200px']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='slot_min_time'>" . __('Horário Início do Dia', 'agendamento') . "</label></td>";
        echo "<td><input type='time' id='slot_min_time' name='slot_min_time' value='" . htmlspecialchars($config['slot_min_time']) . "' class='form-control' style='width:200px;display:inline-block'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='slot_max_time'>" . __('Horário Fim do Dia', 'agendamento') . "</label></td>";
        echo "<td><input type='time' id='slot_max_time' name='slot_max_time' value='" . htmlspecialchars($config['slot_max_time']) . "' class='form-control' style='width:200px;display:inline-block'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='slot_duration'>" . __('Duração do Slot (minutos)', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showFromArray('slot_duration', [
            '00:15:00' => '15 min',
            '00:30:00' => '30 min',
            '00:60:00' => '60 min',
        ], ['value' => $config['slot_duration'], 'display' => true, 'width' => '200px']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='default_event_duration'>" . __('Duração Padrão do Agendamento (minutos)', 'agendamento') . "</label></td>";
        echo "<td><input type='number' id='default_event_duration' name='default_event_duration' value='" . (int) $config['default_event_duration'] . "' min='15' max='480' step='15' class='form-control' style='width:200px;display:inline-block'>";
        echo "&nbsp;<small>" . __('Entre 15 e 480 minutos', 'agendamento') . "</small></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='calendar_height'>" . __('Altura do Calendário (px)', 'agendamento') . "</label></td>";
        echo "<td><input type='number' id='calendar_height' name='calendar_height' value='" . (int) $config['calendar_height'] . "' min='400' max='1200' step='50' class='form-control' style='width:200px;display:inline-block'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='business_days'>" . __('Dias Úteis', 'agendamento') . "</label></td>";
        echo "<td>";
        $activeDays = array_map('intval', explode(',', $config['business_days']));
        $dayNames = [
            1 => __('Segunda', 'agendamento'),
            2 => __('Terça', 'agendamento'),
            3 => __('Quarta', 'agendamento'),
            4 => __('Quinta', 'agendamento'),
            5 => __('Sexta', 'agendamento'),
            6 => __('Sábado', 'agendamento'),
            0 => __('Domingo', 'agendamento'),
        ];
        foreach ($dayNames as $num => $name) {
            $checked = in_array($num, $activeDays) ? ' checked' : '';
            echo "<label class='me-3'><input type='checkbox' name='business_days[]' value='" . $num . "'" . $checked . "> " . htmlspecialchars($name) . "</label>";
        }
        echo "</td></tr>";

        echo "</table><br>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'><i class='ti ti-settings me-1'></i>" . __('Comportamento', 'agendamento') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='auto_create_task'>" . __('Criar TicketTask automaticamente ao agendar', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showYesNo('auto_create_task', $config['auto_create_task']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='notify_technician'>" . __('Notificar técnico ao criar agendamento', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showYesNo('notify_technician', $config['notify_technician']);
        echo "</td></tr>";

        echo "</table><br>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<td class='center' colspan='2'>";
        echo "<input type='submit' name='update_config' class='btn btn-primary' value='" . __('Salvar Configurações', 'agendamento') . "'>";
        echo "</td></tr>";
        echo "</table>";

        Html::closeForm();
        echo "</div>";
    }

    public static function processForm(array $input): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            return;
        }

        $businessDays = isset($input['business_days']) && is_array($input['business_days'])
            ? implode(',', array_map('intval', $input['business_days']))
            : '1,2,3,4,5';

        $data = [
            'default_view' => in_array($input['default_view'] ?? '', ['day', 'week', 'month']) ? $input['default_view'] : 'week',
            'slot_min_time' => self::sanitizeTime($input['slot_min_time'] ?? '07:00'),
            'slot_max_time' => self::sanitizeTime($input['slot_max_time'] ?? '21:00'),
            'slot_duration' => in_array($input['slot_duration'] ?? '', ['00:15:00', '00:30:00', '00:60:00']) ? $input['slot_duration'] : '00:30:00',
            'default_event_duration' => max(15, min(480, (int) ($input['default_event_duration'] ?? 60))),
            'auto_create_task' => (int) ($input['auto_create_task'] ?? 1),
            'notify_technician' => (int) ($input['notify_technician'] ?? 0),
            'calendar_height' => max(400, min(1200, (int) ($input['calendar_height'] ?? 650))),
            'business_days' => $businessDays,
        ];

        GlpiConfig::setConfigurationValues(self::CONTEXT, $data);

        Session::addMessageAfterRedirect(
            __('Configurações salvas com sucesso!', 'agendamento'),
            false,
            INFO
        );
    }

    private static function sanitizeTime(string $time): string
    {
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time;
        }
        return '07:00';
    }
}
