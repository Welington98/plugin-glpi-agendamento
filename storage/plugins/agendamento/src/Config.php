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
        'google_client_id' => '',
        'google_client_secret' => '',
        'google_sync_enabled' => 0,
        'google_calendar_id' => 'primary',
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
        global $CFG_GLPI;

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
        echo "<td><label for='default_view'><i class='ti ti-layout-grid me-1'></i>" . __('Visualização Padrão', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showFromArray('default_view', [
            'day' => __('Diário', 'agendamento'),
            'week' => __('Semanal', 'agendamento'),
            'month' => __('Mensal', 'agendamento'),
        ], ['value' => $config['default_view'], 'display' => true, 'width' => '200px']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='slot_min_time'><i class='ti ti-sunrise me-1'></i>" . __('Horário Início do Dia', 'agendamento') . "</label></td>";
        echo "<td><input type='time' id='slot_min_time' name='slot_min_time' value='" . htmlspecialchars($config['slot_min_time']) . "' class='form-control' style='width:200px;display:inline-block'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='slot_max_time'><i class='ti ti-sunset me-1'></i>" . __('Horário Fim do Dia', 'agendamento') . "</label></td>";
        echo "<td><input type='time' id='slot_max_time' name='slot_max_time' value='" . htmlspecialchars($config['slot_max_time']) . "' class='form-control' style='width:200px;display:inline-block'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='slot_duration'><i class='ti ti-clock-hour-3 me-1'></i>" . __('Duração do Slot (minutos)', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showFromArray('slot_duration', [
            '00:15:00' => '15 min',
            '00:30:00' => '30 min',
            '00:60:00' => '60 min',
        ], ['value' => $config['slot_duration'], 'display' => true, 'width' => '200px']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='default_event_duration'><i class='ti ti-hourglass me-1'></i>" . __('Duração Padrão do Agendamento (minutos)', 'agendamento') . "</label></td>";
        echo "<td><input type='number' id='default_event_duration' name='default_event_duration' value='" . (int) $config['default_event_duration'] . "' min='15' max='480' step='15' class='form-control' style='width:200px;display:inline-block'>";
        echo "&nbsp;<small>" . __('Entre 15 e 480 minutos', 'agendamento') . "</small></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='calendar_height'><i class='ti ti-arrows-vertical me-1'></i>" . __('Altura do Calendário (px)', 'agendamento') . "</label></td>";
        echo "<td><input type='number' id='calendar_height' name='calendar_height' value='" . (int) $config['calendar_height'] . "' min='400' max='1200' step='50' class='form-control' style='width:200px;display:inline-block'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='business_days'><i class='ti ti-calendar-week me-1'></i>" . __('Dias Úteis', 'agendamento') . "</label></td>";
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
        echo "<td><label for='auto_create_task'><i class='ti ti-subtask me-1'></i>" . __('Criar TicketTask automaticamente ao agendar', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showYesNo('auto_create_task', $config['auto_create_task']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='notify_technician'><i class='ti ti-bell me-1'></i>" . __('Notificar técnico ao criar agendamento', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showYesNo('notify_technician', $config['notify_technician']);
        echo "</td></tr>";

        echo "</table><br>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'><i class='ti ti-brand-google me-1'></i>" . __('Google Calendar', 'agendamento') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='google_sync_enabled'><i class='ti ti-refresh me-1'></i>" . __('Habilitar sincronização com Google Calendar', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showYesNo('google_sync_enabled', $config['google_sync_enabled']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='google_client_id'><i class='ti ti-key me-1'></i>" . __('Google Client ID', 'agendamento') . "</label></td>";
        echo "<td><input type='text' id='google_client_id' name='google_client_id' value='" . htmlspecialchars($config['google_client_id']) . "' class='form-control' style='width:400px;display:inline-block' placeholder='xxxx.apps.googleusercontent.com'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='google_client_secret'><i class='ti ti-lock me-1'></i>" . __('Google Client Secret', 'agendamento') . "</label></td>";
        echo "<td><input type='password' id='google_client_secret' name='google_client_secret' value='' class='form-control' style='width:400px;display:inline-block' placeholder='" . (trim($config['google_client_secret'] ?? '') !== '' ? '••••••••' : '') . "'>";
        echo "&nbsp;<small>" . __('Deixe vazio para manter o valor atual', 'agendamento') . "</small></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='google_calendar_id'><i class='ti ti-calendar me-1'></i>" . __('Calendar ID padrão', 'agendamento') . "</label></td>";
        echo "<td><input type='text' id='google_calendar_id' name='google_calendar_id' value='" . htmlspecialchars($config['google_calendar_id'] ?? 'primary') . "' class='form-control' style='width:400px;display:inline-block'>";
        echo "&nbsp;<small>" . __('Use "primary" para o calendário principal', 'agendamento') . "</small></td>";
        echo "</tr>";

        if (trim($config['google_client_id'] ?? '') !== '') {
            $redirectUri = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/agendamento/front/google_callback.php';
            echo "<tr class='tab_bg_1'>";
            echo "<td><i class='ti ti-link me-1'></i>" . __('URI de Redirecionamento OAuth', 'agendamento') . "</td>";
            echo "<td><code>" . htmlspecialchars($redirectUri) . "</code>";
            echo "&nbsp;<small>" . __('Configure este URI no Google Cloud Console', 'agendamento') . "</small></td>";
            echo "</tr>";
        }

        echo "</table><br>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<td class='center' colspan='2'>";
        echo "<button type='submit' name='update_config' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar Configurações', 'agendamento') . "</button>&nbsp;";
echo "<a href='" . htmlspecialchars($pluginWebDir) . "/front/config.php' class='btn btn-outline-secondary ms-2'><i class='ti ti-arrow-back me-1'></i>" . __('Voltar', 'agendamento') . "</a>";
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
            'google_sync_enabled' => (int) ($input['google_sync_enabled'] ?? 0),
            'google_client_id' => trim((string) ($input['google_client_id'] ?? '')),
            'google_calendar_id' => trim((string) ($input['google_calendar_id'] ?? 'primary')) ?: 'primary',
        ];

        $newSecret = trim((string) ($input['google_client_secret'] ?? ''));
        if ($newSecret !== '') {
            $data['google_client_secret'] = GoogleCalendarAuth::encryptSecret($newSecret);
        }

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
