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
        'reminder_hours_before' => 24,
        'calendar_height' => 650,
        'business_days' => '1,2,3,4,5',
        'technician_profile_ids' => '',
        'agendamento_tipos' => "Entrega\nRetirada\nVisita Técnica",
        'agendamento_tipo_obrigatorio' => 0,
        'google_client_id' => '',
        'google_client_secret' => '',
        'google_sync_enabled' => 0,
        'google_calendar_id' => 'primary',
        'google_force_connection' => 0,
        'whatsapp_template_new' => "Olá! Seu agendamento foi criado.\n\nChamado: ##agendamento.ticket_id##\nTécnico: ##agendamento.technician##\nData: ##agendamento.date_start## até ##agendamento.date_end##\n\nMais detalhes: ##agendamento.url##",
        'whatsapp_template_update' => "Olá! Seu agendamento foi atualizado.\n\nChamado: ##agendamento.ticket_id##\nTécnico: ##agendamento.technician##\nNova data: ##agendamento.date_start## até ##agendamento.date_end##\nMotivo: ##agendamento.reschedule_reason##\n\nMais detalhes: ##agendamento.url##",
        'whatsapp_template_cancel' => "Olá! Seu agendamento foi cancelado.\n\nChamado: ##agendamento.ticket_id##\nData que estava marcada: ##agendamento.date_start##\nMotivo: ##agendamento.cancel_reason##\n\nMais detalhes: ##agendamento.url##",
        'whatsapp_template_reminder' => "Olá! Lembrete do seu agendamento.\n\nChamado: ##agendamento.ticket_id##\nTécnico: ##agendamento.technician##\nData: ##agendamento.date_start## até ##agendamento.date_end##\n\nMais detalhes: ##agendamento.url##",
    ];

    public static function getTypeName($nb = 0)
    {
        return __('Configuração Agendamento', 'agendamento');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item->getType() === 'Config') {
            return self::createTabEntry(self::getTypeName(), 0, null, 'ti ti-calendar-event');
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

    public static function getTechnicianProfileIds(): array
    {
        $configured = (string) self::getConfigValue('technician_profile_ids', '');
        if ($configured === '') {
            return [];
        }

        $profileIds = array_map('intval', explode(',', $configured));
        return array_values(array_unique(array_filter($profileIds, static fn(int $id): bool => $id > 0)));
    }

    public static function getTipoOptions(): array
    {
        $configured = (string) self::getConfigValue('agendamento_tipos', '');
        if (trim($configured) === '') {
            return [];
        }

        $options = [];
        foreach (explode("\n", $configured) as $line) {
            $label = trim($line);
            if ($label === '' || isset($options[$label])) {
                continue;
            }
            $options[$label] = $label;
        }

        return $options;
    }

    public static function getProfileOptions(): array
    {
        global $DB;

        $profiles = $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM' => 'glpi_profiles',
            'ORDER' => ['name ASC'],
        ]);
        $options = [];

        foreach ($profiles as $profileData) {
            $profileId = (int) ($profileData['id'] ?? 0);
            $profileName = trim((string) ($profileData['name'] ?? ''));
            if ($profileId <= 0 || $profileName === '') {
                continue;
            }

            $options[$profileId] = $profileName;
        }

        return $options;
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

        echo "<tr class='tab_bg_1'>";
        echo "<td><label><i class='ti ti-tag me-1'></i>" . __('Tipos de agendamento', 'agendamento') . "</label></td>";
        echo "<td>";
        echo "<div id='agendamento_tipos_list' class='d-flex flex-column gap-1' style='max-width:350px'>";
        foreach (self::getTipoOptions() as $tipo) {
            echo "<div class='input-group input-group-sm agendamento-tipo-row'>";
            echo "<input type='text' name='agendamento_tipos[]' class='form-control' value='" . htmlspecialchars($tipo) . "'>";
            echo "<button type='button' class='btn btn-outline-danger agendamento-tipo-remove'><i class='ti ti-x'></i></button>";
            echo "</div>";
        }
        echo "</div>";
        echo "<div class='input-group input-group-sm mt-2' style='max-width:350px'>";
        echo "<input type='text' id='agendamento_tipo_new' class='form-control' placeholder='" . htmlspecialchars(__('Novo tipo...', 'agendamento')) . "'>";
        echo "<button type='button' id='agendamento_tipo_add' class='btn btn-outline-primary'><i class='ti ti-plus'></i> " . __('Adicionar', 'agendamento') . "</button>";
        echo "</div>";
        echo "&nbsp;<small>" . __('Usado no campo "Tipo" dos agendamentos (ex.: Entrega, Retirada, Visita Técnica).', 'agendamento') . "</small>";
        echo "</td>";
        echo "</tr>";

        echo "</table><br>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'><i class='ti ti-settings me-1'></i>" . __('Comportamento', 'agendamento') . "</th></tr>";

        $profileOptions = self::getProfileOptions();
        $selectedTechnicianProfiles = self::getTechnicianProfileIds();

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='technician_profile_ids'><i class='ti ti-user-cog me-1'></i>" . __('Perfis permitidos para técnicos', 'agendamento') . "</label></td>";
        echo "<td>";
        if ($profileOptions === []) {
            echo "<span class='text-muted'>" . __('Nenhum perfil disponível.', 'agendamento') . "</span>";
        } else {
            echo "<div id='technician_profile_ids' class='d-flex flex-wrap gap-3'>";
            foreach ($profileOptions as $profileId => $profileName) {
                $checked = in_array((int) $profileId, $selectedTechnicianProfiles, true) ? ' checked' : '';
                echo "<label class='me-3'><input type='checkbox' name='technician_profile_ids[]' value='" . (int) $profileId . "'" . $checked . "> " . htmlspecialchars($profileName) . "</label>";
            }
            echo "</div>";
            echo "<div><small>" . __('Se nenhum perfil for selecionado, todos os usuários continuarão disponíveis.', 'agendamento') . "</small></div>";
        }
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='auto_create_task'><i class='ti ti-subtask me-1'></i>" . __('Criar TicketTask automaticamente ao agendar', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showYesNo('auto_create_task', $config['auto_create_task']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='agendamento_tipo_obrigatorio'><i class='ti ti-asterisk me-1'></i>" . __('Tornar o campo "Tipo" obrigatório', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showYesNo('agendamento_tipo_obrigatorio', $config['agendamento_tipo_obrigatorio']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='notify_technician'><i class='ti ti-bell me-1'></i>" . __('Notificar técnico e solicitante nos eventos do agendamento', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showYesNo('notify_technician', $config['notify_technician']);
        echo "&nbsp;<small>" . __('Modelos padrão de e-mail já são criados automaticamente (Configurar > Notificações, itemtype "Agendamento") e podem ser personalizados por lá.', 'agendamento') . "</small></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='reminder_hours_before'><i class='ti ti-alarm me-1'></i>" . __('Enviar lembrete X horas antes do agendamento', 'agendamento') . "</label></td>";
        echo "<td><input type='number' id='reminder_hours_before' name='reminder_hours_before' value='" . (int) $config['reminder_hours_before'] . "' min='1' max='168' step='1' class='form-control' style='width:200px;display:inline-block'>";
        echo "&nbsp;<small>" . __('A execução da ação automática é controlada em Configurar > Ações automáticas.', 'agendamento') . "</small></td>";
        echo "</tr>";

        echo "</table><br>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'><i class='ti ti-brand-whatsapp me-1'></i>" . __('Modelos de Mensagem (WhatsApp)', 'agendamento') . "</th></tr>";
        echo "<tr class='tab_bg_1'><td colspan='2'><small>" . __('Textos usados como ponto de partida para enviar por WhatsApp manualmente. O envio automático ainda não está integrado — por enquanto, o e-mail é o canal padrão de notificação.', 'agendamento') . "</small></td></tr>";

        $whatsappFields = [
            'whatsapp_template_new' => __('Agendamento criado', 'agendamento'),
            'whatsapp_template_update' => __('Agendamento atualizado/reagendado', 'agendamento'),
            'whatsapp_template_cancel' => __('Agendamento cancelado', 'agendamento'),
            'whatsapp_template_reminder' => __('Lembrete de agendamento', 'agendamento'),
        ];
        foreach ($whatsappFields as $fieldName => $fieldLabel) {
            echo "<tr class='tab_bg_1'>";
            echo "<td><label for='" . $fieldName . "'>" . htmlspecialchars($fieldLabel) . "</label></td>";
            echo "<td><textarea id='" . $fieldName . "' name='" . $fieldName . "' class='form-control' rows='4' style='width:350px;display:inline-block'>" . htmlspecialchars($config[$fieldName]) . "</textarea></td>";
            echo "</tr>";
        }

        echo "</table><br>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'><i class='ti ti-brand-google me-1'></i>" . __('Google Calendar', 'agendamento') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='google_sync_enabled'><i class='ti ti-refresh me-1'></i>" . __('Habilitar sincronização com Google Calendar', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showYesNo('google_sync_enabled', $config['google_sync_enabled']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='google_force_connection'><i class='ti ti-lock me-1'></i>" . __('Forçar conexão do técnico com o Google Calendar', 'agendamento') . "</label></td>";
        echo "<td>";
        Dropdown::showYesNo('google_force_connection', $config['google_force_connection']);
        echo "&nbsp;<small>" . __('Se ativado (e a sincronização acima estiver habilitada), o técnico não conseguirá ver nem criar/editar agendamentos até conectar sua conta Google.', 'agendamento') . "</small></td>";
        echo "</tr>";

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

        echo <<<'HTML'
        <script>
        (function() {
            const list = document.getElementById('agendamento_tipos_list');
            const newInput = document.getElementById('agendamento_tipo_new');
            const addBtn = document.getElementById('agendamento_tipo_add');

            const bindRemove = (row) => {
                row.querySelector('.agendamento-tipo-remove').addEventListener('click', () => row.remove());
            };

            const addFromInput = () => {
                const value = newInput.value.trim();
                if (value === '') { return; }
                const row = document.createElement('div');
                row.className = 'input-group input-group-sm agendamento-tipo-row';
                row.innerHTML = "<input type='text' name='agendamento_tipos[]' class='form-control'>"
                    + "<button type='button' class='btn btn-outline-danger agendamento-tipo-remove'><i class='ti ti-x'></i></button>";
                row.querySelector('input').value = value;
                list.appendChild(row);
                bindRemove(row);
                newInput.value = '';
                newInput.focus();
            };

            addBtn.addEventListener('click', addFromInput);
            newInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); addFromInput(); }
            });
            list.querySelectorAll('.agendamento-tipo-row').forEach(bindRemove);
        })();
        </script>
        HTML;

        Html::closeForm();
        echo "</div>";
    }

    public static function processForm(array $input): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            return;
        }

        $technicianProfileIds = isset($input['technician_profile_ids']) && is_array($input['technician_profile_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $input['technician_profile_ids']), static fn(int $id): bool => $id > 0)))
            : [];

        $businessDays = isset($input['business_days']) && is_array($input['business_days'])
            ? implode(',', array_map('intval', $input['business_days']))
            : '1,2,3,4,5';

        $agendamentoTipos = [];
        if (isset($input['agendamento_tipos']) && is_array($input['agendamento_tipos'])) {
            foreach ($input['agendamento_tipos'] as $line) {
                $label = trim((string) $line);
                if ($label === '' || in_array($label, $agendamentoTipos, true)) {
                    continue;
                }
                $agendamentoTipos[] = $label;
            }
        }

        $data = [
            'default_view' => in_array($input['default_view'] ?? '', ['day', 'week', 'month']) ? $input['default_view'] : 'week',
            'slot_min_time' => self::sanitizeTime($input['slot_min_time'] ?? '07:00'),
            'slot_max_time' => self::sanitizeTime($input['slot_max_time'] ?? '21:00'),
            'slot_duration' => in_array($input['slot_duration'] ?? '', ['00:15:00', '00:30:00', '00:60:00']) ? $input['slot_duration'] : '00:30:00',
            'default_event_duration' => max(15, min(480, (int) ($input['default_event_duration'] ?? 60))),
            'auto_create_task' => (int) ($input['auto_create_task'] ?? 1),
            'notify_technician' => (int) ($input['notify_technician'] ?? 0),
            'reminder_hours_before' => max(1, min(168, (int) ($input['reminder_hours_before'] ?? 24))),
            'calendar_height' => max(400, min(1200, (int) ($input['calendar_height'] ?? 650))),
            'business_days' => $businessDays,
            'technician_profile_ids' => implode(',', $technicianProfileIds),
            'agendamento_tipos' => implode("\n", $agendamentoTipos),
            'agendamento_tipo_obrigatorio' => (int) ($input['agendamento_tipo_obrigatorio'] ?? 0),
            'google_sync_enabled' => (int) ($input['google_sync_enabled'] ?? 0),
            'google_force_connection' => (int) ($input['google_force_connection'] ?? 0),
            'google_client_id' => trim((string) ($input['google_client_id'] ?? '')),
            'google_calendar_id' => trim((string) ($input['google_calendar_id'] ?? 'primary')) ?: 'primary',
            'whatsapp_template_new' => trim((string) ($input['whatsapp_template_new'] ?? '')),
            'whatsapp_template_update' => trim((string) ($input['whatsapp_template_update'] ?? '')),
            'whatsapp_template_cancel' => trim((string) ($input['whatsapp_template_cancel'] ?? '')),
            'whatsapp_template_reminder' => trim((string) ($input['whatsapp_template_reminder'] ?? '')),
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
