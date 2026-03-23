<?php

namespace GlpiPlugin\Agendamento;

use DBConnection;
use DateInterval;
use DateTimeImmutable;
use Dropdown;
use Html;
use Session;
use Ticket as GlpiTicket;

use GlpiPlugin\Agendamento\GoogleCalendarAuth;
use GlpiPlugin\Agendamento\GoogleCalendarSync;

class Agendamento
{
    private const TABLE = 'glpi_plugin_agendamento_agendamentos';

    public const STATUS_AGENDADO = 'agendado';
    public const STATUS_CONFIRMADO = 'confirmado';
    public const STATUS_CANCELADO = 'cancelado';
    public const STATUS_REALIZADO = 'realizado';

    public static function showOverview(?string $anchorDate = null, string $view = 'week'): void
    {
        global $CFG_GLPI;

        self::ensureTableExists();

        $view = self::normalizeView($view);
        $period = self::getPeriodWindow($anchorDate, $view);
        // Using AJAX loading for ticket options now, removed bulk loading for performance
        $tecnicosOptions = self::getTecnicosOptions();
        $statusOptions = self::getStatusOptions();
        $defaultDateTime = self::getDefaultDateTimeValues();
        $currentDate = $period['anchor']->format('Y-m-d');
        $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        $baseUrl = $rootDoc . '/plugins/agendamento/front/agendamento.php';
        $pluginConfig = Config::getConfig();
        $canCreate = Session::haveRight('plugin_agendamento', CREATE);
        $canUpdate = Session::haveRight('plugin_agendamento', UPDATE);

        $viewMode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : 'calendar';
        if (!in_array($viewMode, ['list', 'calendar'], true)) {
            $viewMode = 'calendar';
        }
        $statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
        $filterTechId = isset($_GET['tech_id']) ? (int) $_GET['tech_id'] : 0;
        $requestedTicketId = isset($_GET['ticket_id']) ? (int) $_GET['ticket_id'] : 0;
        $autoOpenCreateModal = $canCreate
            && isset($_GET['open_create'])
            && (int) $_GET['open_create'] === 1
            && $requestedTicketId > 0;

        $buildOverviewUrl = static function (array $extra = []) use ($baseUrl): string {
            $params = array_filter($extra, fn($v) => $v !== '' && $v !== 0 && $v !== '0');
            return $baseUrl . ($params !== [] ? '?' . http_build_query($params) : '');
        };

        $allAgendamentos = self::getAllAgendamentos('', $filterTechId > 0 ? $filterTechId : null, 500);
        $counts = ['total' => 0, self::STATUS_AGENDADO => 0, self::STATUS_CONFIRMADO => 0, self::STATUS_CANCELADO => 0, self::STATUS_REALIZADO => 0];
        foreach ($allAgendamentos as $ag) {
            $s = self::normalizeStatus((string) ($ag['status'] ?? ''));
            $counts['total']++;
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }
        $listAgendamentos = self::getAllAgendamentos($statusFilter, $filterTechId > 0 ? $filterTechId : null, 200);

        $calendarConfig = [
            'eventsUrl' => $rootDoc . '/plugins/agendamento/front/agendamento_calendar.php?action=events' . ($filterTechId > 0 ? '&tech_id=' . $filterTechId : ''),
            'actionsUrl' => $rootDoc . '/plugins/agendamento/front/agendamento_calendar.php',
            'pageUrl' => $baseUrl,
            'initialDate' => $currentDate,
            'initialView' => $view,
            'locale' => 'pt-BR',
            'csrfToken' => Session::getNewCSRFToken(true),
            'ticketMetadata' => [],
            'slotMinTime' => ($pluginConfig['slot_min_time'] ?? '07:00') . ':00',
            'slotMaxTime' => ($pluginConfig['slot_max_time'] ?? '21:00') . ':00',
            'slotDuration' => $pluginConfig['slot_duration'] ?? '00:30:00',
            'calendarHeight' => (int) ($pluginConfig['calendar_height'] ?? 650),
            'defaultEventDuration' => (int) ($pluginConfig['default_event_duration'] ?? 60),
            'businessDays' => array_map('intval', explode(',', $pluginConfig['business_days'] ?? '1,2,3,4,5')),
            'texts' => [
                'today' => __('Hoje', 'agendamento'),
                'month' => __('Mensal', 'agendamento'),
                'week' => __('Semanal', 'agendamento'),
                'day' => __('Diário', 'agendamento'),
                'saveError' => __('Não foi possível atualizar o agendamento.', 'agendamento'),
                'csrfError' => __('A sessão de segurança expirou. Recarregue a página e tente novamente.', 'agendamento'),
                'detailsTitle' => __('Detalhes do agendamento', 'agendamento'),
                'noNotes' => __('Sem observações informadas.', 'agendamento'),
                'noTechnician' => __('Não atribuído', 'agendamento'),
                'noClientContact' => __('Contato não informado.', 'agendamento'),
                'noClientAddress' => __('Endereço não informado.', 'agendamento'),
                'noTask' => __('Sem TicketTask vinculada.', 'agendamento'),
            ],
        ];
        $selectedTicket = isset($_POST['agendamento_tickets_id'])
            ? (string) $_POST['agendamento_tickets_id']
            : ($requestedTicketId > 0 ? (string) $requestedTicketId : '');
        $selectedTechnician = isset($_POST['agendamento_users_id_tech']) ? (string) $_POST['agendamento_users_id_tech'] : '';
        $selectedStatus = isset($_POST['agendamento_status']) ? (string) $_POST['agendamento_status'] : self::STATUS_AGENDADO;
        $notes = isset($_POST['agendamento_observacoes']) ? (string) $_POST['agendamento_observacoes'] : '';
        $selectedClientContact = isset($_POST['agendamento_contato_cliente'])
            ? (string) $_POST['agendamento_contato_cliente']
            : (($selectedTicket !== '' && isset($ticketMetadata[$selectedTicket])) ? (string) ($ticketMetadata[$selectedTicket]['contact'] ?? '') : '');
        $selectedClientAddress = isset($_POST['agendamento_endereco_cliente'])
            ? (string) $_POST['agendamento_endereco_cliente']
            : (($selectedTicket !== '' && isset($ticketMetadata[$selectedTicket])) ? (string) ($ticketMetadata[$selectedTicket]['address'] ?? '') : '');
        $formAction = isset($_POST['agendamento_action']) ? trim((string) $_POST['agendamento_action']) : 'create';
        $editingAgendamentoId = isset($_POST['agendamento_id']) ? (int) $_POST['agendamento_id'] : 0;
        ?>
        
        <!-- Page Header -->
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h3 class="card-title mb-0">
                    <i class="ti ti-calendar-event me-2"></i>
                    <?php echo htmlescape(__('GLPI Agenda', 'agendamento')); ?>
                </h3>
                <div class="d-flex gap-2">
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="<?php echo htmlescape($buildOverviewUrl(['mode' => 'list', 'status' => $statusFilter, 'tech_id' => $filterTechId])); ?>" class="btn <?php echo $viewMode === 'list' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <i class="ti ti-list me-1"></i><?php echo htmlescape(__('Lista', 'agendamento')); ?>
                        </a>
                        <a href="<?php echo htmlescape($buildOverviewUrl(['mode' => 'calendar'])); ?>" class="btn <?php echo $viewMode === 'calendar' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <i class="ti ti-calendar me-1"></i><?php echo htmlescape(__('Calendário', 'agendamento')); ?>
                        </a>
                    </div>
                    <a href="<?php echo htmlescape($rootDoc . '/plugins/agendamento/front/meus_agendamentos.php'); ?>" class="btn btn-sm btn-outline-primary">
                        <i class="ti ti-calendar-user me-1"></i><?php echo htmlescape(__('Meus Agendamentos', 'agendamento')); ?>
                    </a>
                </div>
            </div>
        </div>

        <?php if ($viewMode === 'list') { ?>
            <div class="row g-3 mb-3">
                <?php
                $badges = [
                    '' => ['label' => __('Todos', 'agendamento'), 'count' => $counts['total'], 'color' => 'primary'],
                    self::STATUS_AGENDADO => ['label' => __('Agendados', 'agendamento'), 'count' => $counts[self::STATUS_AGENDADO], 'color' => 'info'],
                    self::STATUS_CONFIRMADO => ['label' => __('Confirmados', 'agendamento'), 'count' => $counts[self::STATUS_CONFIRMADO], 'color' => 'success'],
                    self::STATUS_REALIZADO => ['label' => __('Realizados', 'agendamento'), 'count' => $counts[self::STATUS_REALIZADO], 'color' => 'secondary'],
                    self::STATUS_CANCELADO => ['label' => __('Cancelados', 'agendamento'), 'count' => $counts[self::STATUS_CANCELADO], 'color' => 'danger'],
                ];
                foreach ($badges as $filterKey => $badge) {
                    $active = $statusFilter === $filterKey ? ' active' : '';
                    $badgeParams = ['mode' => 'list'];
                    if ($filterKey !== '') {
                        $badgeParams['status'] = $filterKey;
                    }
                    if ($filterTechId > 0) {
                        $badgeParams['tech_id'] = $filterTechId;
                    }
                    echo "<div class='col-auto'>";
                    echo "<a href='" . htmlescape($buildOverviewUrl($badgeParams)) . "' class='btn btn-outline-" . $badge['color'] . $active . "'>";
                    echo htmlescape($badge['label']) . " <span class='badge bg-" . $badge['color'] . " ms-1'>" . $badge['count'] . "</span>";
                    echo "</a></div>";
                }
                ?>
            </div>

            <?php if ($listAgendamentos === []) { ?>
                <div class="alert alert-info">
                    <i class="ti ti-info-circle me-1"></i>
                    <?php echo htmlescape(__('Nenhum agendamento encontrado.', 'agendamento')); ?>
                </div>
            <?php } else { ?>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-vcenter table-hover card-table">
                            <thead>
                                <tr>
                                    <th><?php echo htmlescape(__('Chamado', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Título', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Técnico', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Início', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Fim', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Status', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Contato', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Observações', 'agendamento')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($listAgendamentos as $ag) {
                                    $ticketId = (int) ($ag['ticket_id'] ?? $ag['tickets_id'] ?? 0);
                                    $status = self::normalizeStatus((string) ($ag['status'] ?? ''));
                                    $palette = self::getStatusPalette($status);
                                    $startAt = strtotime((string) ($ag['data_hora_inicio'] ?? ''));
                                    $endAt = strtotime((string) ($ag['data_hora_fim'] ?? ''));
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo htmlescape($rootDoc . '/front/ticket.form.php?id=' . $ticketId); ?>">
                                            #<?php echo $ticketId; ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlescape((string) ($ag['ticket_name'] ?? __('Sem título', 'agendamento'))); ?></td>
                                    <td><?php echo htmlescape(trim((string) ($ag['tecnico_nome'] ?? '')) ?: '-'); ?></td>
                                    <td><?php echo $startAt !== false ? date('d/m/Y H:i', $startAt) : '-'; ?></td>
                                    <td><?php echo $endAt !== false ? date('d/m/Y H:i', $endAt) : '-'; ?></td>
                                    <td>
                                        <span class="badge" style="background-color:<?php echo htmlescape($palette['background']); ?>;color:<?php echo htmlescape($palette['text']); ?>;border:1px solid <?php echo htmlescape($palette['border']); ?>">
                                            <?php echo htmlescape(self::getStatusLabel($status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlescape(trim((string) ($ag['contato_cliente'] ?? '')) ?: '-'); ?></td>
                                    <td><?php echo htmlescape(mb_strimwidth(trim((string) ($ag['observacoes'] ?? '')), 0, 60, '...') ?: '-'); ?></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php } ?>
        <?php } else { ?>

        <div class="row g-3">
            <!-- Sidebar -->
            <div class="col-12 col-lg-3">
                <div class="card">
                    <div class="card-body d-flex flex-column gap-3">
                        <button type="button" class="btn btn-warning w-100 fw-bold" data-open-modal="plugin-agendamento-create-modal"<?php echo $canCreate ? '' : ' disabled'; ?>>
                            <i class="ti ti-plus me-1"></i>
                            <?php echo htmlescape(__('Novo Agendamento', 'agendamento')); ?>
                        </button>

                        <hr class="my-0">

                        <div class="mb-2">
                            <label class="form-label fw-semibold text-uppercase small mb-1"><?php echo htmlescape(__('Técnico Responsável', 'agendamento')); ?></label>
                            <?php
                            Dropdown::showFromArray('plugin_agendamento_filter_tech', $tecnicosOptions, [
                                'value' => $filterTechId,
                                'display_emptychoice' => true,
                                'emptylabel' => __('Todos os Técnicos', 'agendamento'),
                                'width' => '100%',
                                'rand' => 1200,
                            ]);
                            ?>
                            <a id="plugin-agendamento-view-tech-agenda" href="#" class="btn btn-sm btn-outline-info w-100 mt-2" style="display:none;">
                                <i class="ti ti-calendar-user me-1"></i><?php echo htmlescape(__('Ver Agenda do Técnico', 'agendamento')); ?>
                            </a>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold text-uppercase small mb-1"><?php echo htmlescape(__('Status do Chamado', 'agendamento')); ?></label>
                            <select id="plugin-agendamento-filter-status" class="form-select form-select-sm">
                                <option value=""><?php echo htmlescape(__('Todos os Status', 'agendamento')); ?></option>
                                <?php foreach ($statusOptions as $statusKey => $statusLabel) { ?>
                                    <option value="<?php echo htmlescape($statusKey); ?>"><?php echo htmlescape($statusLabel); ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="mt-auto pt-3 border-top">
                            <span class="text-uppercase fw-semibold small text-muted d-block mb-2"><?php echo htmlescape(__('Legenda', 'agendamento')); ?></span>
                            <?php foreach ($statusOptions as $statusKey => $statusLabel) {
                                $palette = self::getStatusPalette($statusKey);
                                ?>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="d-inline-block rounded-circle flex-shrink-0" style="width: 10px; height: 10px; background: <?php echo htmlescape($palette['border']); ?>"></span>
                                    <span class="small"><?php echo htmlescape($statusLabel); ?></span>
                                </div>
                            <?php } ?>
                        </div>

                        <div class="text-center text-muted mt-3" style="font-size: 0.7rem;">
                            Plugin Agendamentos v<?php echo htmlescape(defined('PLUGIN_AGENDAMENTO_VERSION') ? PLUGIN_AGENDAMENTO_VERSION : 'dev'); ?><br>GLPI 11.0+
                        </div>
                    </div>
                </div>
            </div>

            <!-- Calendar Area -->
            <div class="col-12 col-lg-9">
                <div class="card">
                    <div class="card-body p-3">
                        <div id="plugin-agendamento-calendar" class="plugin-agendamento-calendar" data-config="<?php echo htmlescape(json_encode($calendarConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)); ?>"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Modal (Standard Bootstrap 5) -->
        <div class="modal fade" id="plugin-agendamento-create-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-calendar-plus me-2"></i>
                            <span id="plugin-agendamento-form-title"><?php echo htmlescape(__('Agendar chamado', 'agendamento')); ?></span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post" action="<?php echo htmlescape(self::buildPageUrl($baseUrl, $currentDate, $view)); ?>">
                        <div class="modal-body">
                            <input type="hidden" name="_glpi_csrf_token" value="<?php echo Session::getNewCSRFToken(); ?>">
                            <input type="hidden" name="date" value="<?php echo htmlescape($currentDate); ?>" class="plugin-agendamento-sync-date">
                            <input type="hidden" name="view" value="<?php echo htmlescape($view); ?>" class="plugin-agendamento-sync-view">
                            <input type="hidden" name="agendamento_action" id="plugin-agendamento-form-action" value="<?php echo htmlescape($formAction === 'edit' ? 'edit' : 'create'); ?>">
                            <input type="hidden" name="agendamento_id" id="plugin-agendamento-form-id" value="<?php echo htmlescape((string) $editingAgendamentoId); ?>">

                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="dropdown_agendamento_tickets_id1101" class="form-label required"><?php echo htmlescape(__('Chamado (Ticket)', 'agendamento')); ?></label>
                                    <?php
                                    Dropdown::show('Ticket', [
                                        'name' => 'agendamento_tickets_id',
                                        'value' => $selectedTicket,
                                        'width' => '100%',
                                        'rand' => 1101,
                                        'entity' => $_SESSION['glpiactive_entity'] ?? 0,
                                        'comments' => false,
                                        'init' => false,
                                    ]);
                                    ?>
                                </div>

                                <div class="col-md-6">
                                    <label for="agendamento_data_hora_inicio" class="form-label required"><?php echo htmlescape(__('Data Início', 'agendamento')); ?></label>
                                    <input type="datetime-local" id="agendamento_data_hora_inicio" name="agendamento_data_hora_inicio" class="form-control" required value="<?php echo htmlescape(isset($_POST['agendamento_data_hora_inicio']) ? (string) $_POST['agendamento_data_hora_inicio'] : $defaultDateTime['start']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="agendamento_data_hora_fim" class="form-label"><?php echo htmlescape(__('Data Fim Prevista', 'agendamento')); ?></label>
                                    <input type="datetime-local" id="agendamento_data_hora_fim" name="agendamento_data_hora_fim" class="form-control" value="<?php echo htmlescape(isset($_POST['agendamento_data_hora_fim']) ? (string) $_POST['agendamento_data_hora_fim'] : $defaultDateTime['end']); ?>">
                                </div>

                                <div class="col-12">
                                    <label class="form-label required"><?php echo htmlescape(__('Técnico', 'agendamento')); ?></label>
                                    <?php
                                    Dropdown::showFromArray('agendamento_users_id_tech', $tecnicosOptions, [
                                        'value' => $selectedTechnician,
                                        'display_emptychoice' => true,
                                        'emptylabel' => __('Selecione um técnico...', 'agendamento'),
                                        'width' => '100%',
                                        'rand' => 1102,
                                    ]);
                                    ?>
                                </div>

                                <div class="col-md-6">
                                    <label for="agendamento_contato_cliente" class="form-label"><?php echo htmlescape(__('Contato do Cliente', 'agendamento')); ?></label>
                                    <input type="text" id="agendamento_contato_cliente" name="agendamento_contato_cliente" class="form-control" value="<?php echo htmlescape($selectedClientContact); ?>" placeholder="<?php echo htmlescape(__('Autopreenchido pelo chamado', 'agendamento')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="agendamento_endereco_cliente" class="form-label"><?php echo htmlescape(__('Endereço do Cliente', 'agendamento')); ?></label>
                                    <textarea id="agendamento_endereco_cliente" name="agendamento_endereco_cliente" class="form-control" rows="2" placeholder="<?php echo htmlescape(__('Endereço...', 'agendamento')); ?>"><?php echo htmlescape($selectedClientAddress); ?></textarea>
                                </div>

                                <div class="col-md-6">
                                    <label for="agendamento_status" class="form-label"><?php echo htmlescape(__('Status', 'agendamento')); ?></label>
                                    <select id="agendamento_status" name="agendamento_status" class="form-select">
                                        <?php foreach ($statusOptions as $statusKey => $statusLabel) { ?>
                                            <option value="<?php echo htmlescape($statusKey); ?>"<?php echo $selectedStatus === $statusKey ? ' selected' : ''; ?>><?php echo htmlescape($statusLabel); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="agendamento_observacoes" class="form-label"><?php echo htmlescape(__('Observações', 'agendamento')); ?></label>
                                    <input type="text" id="agendamento_observacoes" name="agendamento_observacoes" class="form-control" value="<?php echo htmlescape($notes); ?>" placeholder="<?php echo htmlescape(__('Notas...', 'agendamento')); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-top-0 pt-0">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo htmlescape(__('Cancelar', 'agendamento')); ?></button>
                            <button type="submit" name="save_agendamento" value="1" id="plugin-agendamento-form-submit" class="btn btn-primary"<?php echo ($tecnicosOptions === [] || (($formAction === 'edit') ? !$canUpdate : !$canCreate)) ? ' disabled' : ''; ?>>
                                <i class="ti ti-device-floppy me-1"></i>
                                <?php echo htmlescape(__('Salvar Alterações', 'agendamento')); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Details Modal (Standard Bootstrap 5) -->
        <div class="modal fade" id="plugin-agendamento-details-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="plugin-agendamento-detail-title"><?php echo htmlescape(__('Detalhes do agendamento', 'agendamento')); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <span class="badge bg-secondary fs-6" id="plugin-agendamento-detail-status"><?php echo htmlescape(__('Agendado', 'agendamento')); ?></span>
                        </div>

                        <div class="list-group list-group-flush">
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span class="text-muted"><i class="ti ti-clock me-2"></i><?php echo htmlescape(__('Horário', 'agendamento')); ?></span>
                                <strong id="plugin-agendamento-detail-time" class="text-end">-</strong>
                            </div>
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span class="text-muted"><i class="ti ti-user-cog me-2"></i><?php echo htmlescape(__('Técnico', 'agendamento')); ?></span>
                                <strong id="plugin-agendamento-detail-tech" class="text-end">-</strong>
                            </div>
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span class="text-muted"><i class="ti ti-user me-2"></i><?php echo htmlescape(__('Contato', 'agendamento')); ?></span>
                                <strong id="plugin-agendamento-detail-contact" class="text-end">-</strong>
                            </div>
                            <div class="list-group-item px-0">
                                <div class="text-muted mb-1"><i class="ti ti-map-pin me-2"></i><?php echo htmlescape(__('Endereço', 'agendamento')); ?></div>
                                <div id="plugin-agendamento-detail-address" class="fw-bold ps-4">-</div>
                            </div>
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span class="text-muted"><i class="ti ti-ticket me-2"></i><?php echo htmlescape(__('TicketTask', 'agendamento')); ?></span>
                                <strong id="plugin-agendamento-detail-task" class="text-end">-</strong>
                            </div>
                            <div class="list-group-item px-0">
                                <div class="text-muted mb-1"><i class="ti ti-notes me-2"></i><?php echo htmlescape(__('Descrição', 'agendamento')); ?></div>
                                <div id="plugin-agendamento-detail-notes" class="fw-bold ps-4 text-break">-</div>
                            </div>
                        </div>
                    </div>

                    <form method="post" action="<?php echo htmlescape(self::buildPageUrl($baseUrl, $currentDate, $view)); ?>" class="modal-footer d-block">
                        <input type="hidden" name="_glpi_csrf_token" value="<?php echo Session::getNewCSRFToken(); ?>">
                        <input type="hidden" name="date" value="<?php echo htmlescape($currentDate); ?>" class="plugin-agendamento-sync-date">
                        <input type="hidden" name="view" value="<?php echo htmlescape($view); ?>" class="plugin-agendamento-sync-view">
                        <input type="hidden" name="tickets_id" id="plugin-agendamento-detail-ticket-id" value="0">
                        <input type="hidden" name="agendamento_id" id="plugin-agendamento-detail-agendamento-id" value="0">

                        <!-- Cancel Panel -->
                        <div class="bg-light p-3 rounded mb-3" id="plugin-agendamento-cancel-panel" hidden>
                            <div class="mb-2">
                                <label for="plugin-agendamento-cancel-reason" class="form-label fw-bold small text-uppercase text-danger"><?php echo htmlescape(__('Motivo do cancelamento', 'agendamento')); ?></label>
                                <textarea id="plugin-agendamento-cancel-reason" name="cancelamento_motivo" class="form-control" rows="2" placeholder="<?php echo htmlescape(__('Descreva o motivo...', 'agendamento')); ?>" disabled></textarea>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" id="plugin-agendamento-cancel-back" class="btn btn-sm btn-outline-secondary"><?php echo htmlescape(__('Voltar', 'agendamento')); ?></button>
                                <button type="submit" name="update_agendamento_status" value="cancelado" class="btn btn-sm btn-danger"><?php echo htmlescape(__('Confirmar Cancelamento', 'agendamento')); ?></button>
                            </div>
                        </div>

                        <!-- Main Actions -->
                        <div class="d-flex justify-content-between w-100" id="plugin-agendamento-detail-main-actions">
                            <div class="d-flex gap-2">
                                <?php if ($canUpdate) { ?>
                                <button type="button" id="plugin-agendamento-edit-button" class="btn btn-outline-primary" title="<?php echo htmlescape(__('Editar', 'agendamento')); ?>">
                                    <i class="ti ti-pencil"></i>
                                </button>
                                <button type="button" id="plugin-agendamento-cancel-toggle" class="btn btn-outline-danger" title="<?php echo htmlescape(__('Cancelar', 'agendamento')); ?>">
                                    <i class="ti ti-ban"></i>
                                </button>
                                <?php } ?>
                            </div>

                            <div class="d-flex gap-2">
                                <?php if ($canUpdate) { ?>
                                <button type="submit" name="update_agendamento_status" value="confirmado" class="btn btn-outline-success">
                                    <i class="ti ti-check me-1"></i><?php echo htmlescape(__('Confirmar', 'agendamento')); ?>
                                </button>
                                <button type="submit" name="update_agendamento_status" value="realizado" class="btn btn-dark">
                                    <i class="ti ti-checks me-1"></i><?php echo htmlescape(__('Concluir', 'agendamento')); ?>
                                </button>
                                <?php } ?>
                                <a id="plugin-agendamento-detail-ticket-link" href="#" class="btn btn-light border" target="_blank" title="<?php echo htmlescape(__('Abrir Chamado', 'agendamento')); ?>">
                                    <i class="ti ti-external-link"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Reschedule Reason Modal -->
        <div class="modal fade" id="plugin-agendamento-reschedule-modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-calendar-event me-2"></i><?php echo htmlescape(__('Reagendamento', 'agendamento')); ?>
                        </h5>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info d-flex gap-2 align-items-start py-2">
                            <i class="ti ti-info-circle fs-5 mt-1 flex-shrink-0"></i>
                            <span id="plugin-agendamento-reschedule-info" class="small"></span>
                        </div>
                        <div class="mb-1">
                            <label for="plugin-agendamento-reschedule-reason" class="form-label fw-bold">
                                <?php echo htmlescape(__('Motivo do reagendamento', 'agendamento')); ?>
                                <span class="text-danger">*</span>
                            </label>
                            <textarea id="plugin-agendamento-reschedule-reason" class="form-control" rows="3"
                                placeholder="<?php echo htmlescape(__('Descreva o motivo do reagendamento...', 'agendamento')); ?>"></textarea>
                            <div class="invalid-feedback"><?php echo htmlescape(__('O motivo é obrigatório.', 'agendamento')); ?></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" id="plugin-agendamento-reschedule-cancel-btn" class="btn btn-outline-secondary">
                            <i class="ti ti-x me-1"></i><?php echo htmlescape(__('Cancelar', 'agendamento')); ?>
                        </button>
                        <button type="button" id="plugin-agendamento-reschedule-confirm-btn" class="btn btn-warning">
                            <i class="ti ti-calendar-stats me-1"></i><?php echo htmlescape(__('Confirmar Reagendamento', 'agendamento')); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <?php
        $inlineScript = @file_get_contents(\Plugin::getPhpDir('agendamento') . '/public/js/agendamento-calendar.js');
        if ($inlineScript !== false && trim($inlineScript) !== '') {
            echo "<script>\n" . $inlineScript . "\n</script>";
        }
        if ($autoOpenCreateModal) {
            echo "<script>document.addEventListener('DOMContentLoaded', function () {";
            echo "const modalElement = document.getElementById('plugin-agendamento-create-modal');";
            echo "if (!modalElement || typeof bootstrap === 'undefined') { return; }";
            echo "const modal = bootstrap.Modal.getOrCreateInstance(modalElement);";
            echo "modal.show();";
            echo "});</script>";
        }
        ?>
        <?php } ?>
        <?php
    }

    public static function createFromForm(array $data): void
    {
        self::create(self::prepareFormData($data));
    }

    public static function updateFromForm(array $data): void
    {
        $agendamentoId = (int) ($data['agendamento_id'] ?? 0);
        if ($agendamentoId <= 0) {
            throw new \RuntimeException(__('Agendamento inválido.', 'agendamento'));
        }

        self::update($agendamentoId, self::prepareFormData($data));
    }

    public static function create(array $data): int
    {
        global $DB;

        self::ensureTableExists();

        $ticketId = (int) ($data['tickets_id'] ?? 0);
        $technicianId = (int) ($data['users_id_tech'] ?? 0);
        $start = self::normalizeDateTime((string) ($data['data_hora_inicio'] ?? ''));
        $end = self::normalizeDateTime((string) ($data['data_hora_fim'] ?? ''));

        if ($ticketId <= 0 || $technicianId <= 0 || $start === null) {
            throw new \RuntimeException(__('Dados obrigatórios do agendamento não informados.', 'agendamento'));
        }

        $DB->insert(self::TABLE, [
            'tickets_id' => $ticketId,
            'users_id_tech' => $technicianId,
            'tecnico_nome' => self::nullableString($data['tecnico_nome'] ?? null),
            'contato_cliente' => self::nullableString($data['contato_cliente'] ?? null),
            'endereco_cliente' => self::nullableString($data['endereco_cliente'] ?? null),
            'data_hora_inicio' => $start,
            'data_hora_fim' => $end,
            'status' => self::normalizeStatus((string) ($data['status'] ?? self::STATUS_AGENDADO)),
            'observacoes' => self::nullableString($data['observacoes'] ?? null),
            'users_id' => (int) ($data['users_id'] ?? 0),
            'tickettasks_id' => 0,
        ]);

        $agendamentoId = (int) $DB->insertId();
        if ($agendamentoId <= 0) {
            throw new \RuntimeException(__('Não foi possível salvar o agendamento.', 'agendamento'));
        }

        self::syncLinkedTask($agendamentoId);
        self::syncGoogleCalendar($agendamentoId);
        return $agendamentoId;
    }

    public static function update(int $agendamentoId, array $data): void
    {
        global $DB;

        self::ensureTableExists();

        if ($agendamentoId <= 0) {
            throw new \RuntimeException(__('Agendamento inválido.', 'agendamento'));
        }

        $current = self::getById($agendamentoId);
        if ($current === null) {
            throw new \RuntimeException(__('Agendamento não encontrado.', 'agendamento'));
        }

        $ticketId = (int) ($data['tickets_id'] ?? 0);
        $technicianId = (int) ($data['users_id_tech'] ?? 0);
        $start = self::normalizeDateTime((string) ($data['data_hora_inicio'] ?? ''));
        $end = self::normalizeDateTime((string) ($data['data_hora_fim'] ?? ''));

        if ($ticketId <= 0 || $technicianId <= 0 || $start === null) {
            throw new \RuntimeException(__('Dados obrigatórios do agendamento não informados.', 'agendamento'));
        }

        $DB->update(self::TABLE, [
            'tickets_id' => $ticketId,
            'users_id_tech' => $technicianId,
            'tecnico_nome' => self::nullableString($data['tecnico_nome'] ?? null),
            'contato_cliente' => self::nullableString($data['contato_cliente'] ?? null),
            'endereco_cliente' => self::nullableString($data['endereco_cliente'] ?? null),
            'data_hora_inicio' => $start,
            'data_hora_fim' => $end,
            'status' => self::normalizeStatus((string) ($data['status'] ?? self::STATUS_AGENDADO)),
            'observacoes' => self::nullableString($data['observacoes'] ?? null),
            'users_id' => (int) ($current['users_id'] ?? $data['users_id'] ?? Session::getLoginUserID()),
        ], [
            'id' => $agendamentoId,
        ]);

        self::syncLinkedTask($agendamentoId);
        self::syncGoogleCalendar($agendamentoId);
    }

    public static function updateStatus(int $ticketId, int $agendamentoId, string $status, string $cancelReason = ''): void
    {
        global $DB;

        self::ensureTableExists();

        if ($ticketId <= 0 || $agendamentoId <= 0) {
            throw new \RuntimeException(__('Agendamento inválido.', 'agendamento'));
        }

        $status = self::normalizeStatus($status);
        $cancelReason = trim($cancelReason);
        if ($status === self::STATUS_CANCELADO && $cancelReason === '') {
            throw new \RuntimeException(__('Informe o motivo do cancelamento.', 'agendamento'));
        }

        $DB->beginTransaction();

        try {
            $DB->update(self::TABLE, [
                'status' => $status,
            ], [
                'id' => $agendamentoId,
                'tickets_id' => $ticketId,
            ]);

            $agendamento = self::getById($agendamentoId);
            if ($agendamento === null) {
                throw new \RuntimeException(__('Agendamento não encontrado.', 'agendamento'));
            }

            if ($status === self::STATUS_CANCELADO) {
                self::registerCancellationFollowup($ticketId, $agendamento, $cancelReason);
            }

            self::syncLinkedTask($agendamentoId);

            if ($status === self::STATUS_CANCELADO) {
                self::deleteGoogleCalendarEvent($agendamentoId);
            } else {
                self::syncGoogleCalendar($agendamentoId);
            }

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    public static function reschedule(int $ticketId, int $agendamentoId, string $startDateTime, ?string $endDateTime = null, ?string $motivo = null): void
    {
        global $DB;

        self::ensureTableExists();

        $start = self::normalizeDateTime($startDateTime);
        $end = self::normalizeDateTime($endDateTime ?? '');

        if ($ticketId <= 0 || $agendamentoId <= 0 || $start === null) {
            throw new \RuntimeException(__('Reagendamento inválido.', 'agendamento'));
        }

        if ($end !== null && strtotime($end) < strtotime($start)) {
            throw new \RuntimeException(__('A data final deve ser maior ou igual à data inicial.', 'agendamento'));
        }

        $motivoNormalizado = ($motivo !== null && trim($motivo) !== '') ? trim($motivo) : null;

        $dadosAnteriores = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $agendamentoId, 'tickets_id' => $ticketId]])->current();

        $updateData = [
            'data_hora_inicio' => $start,
            'data_hora_fim' => $end,
        ];

        if ($motivoNormalizado !== null) {
            $updateData['motivo_reagendamento'] = $motivoNormalizado;
        }

        $DB->update(self::TABLE, $updateData, [
            'id' => $agendamentoId,
            'tickets_id' => $ticketId,
        ]);

        if ($dadosAnteriores && $motivoNormalizado !== null) {
            self::registerRescheduleFollowup($ticketId, $dadosAnteriores, $start, $end, $motivoNormalizado);
        }

        self::syncLinkedTask($agendamentoId);
        self::syncGoogleCalendar($agendamentoId);
    }

    public static function getForPeriod(string $startDateTime, string $endDateTime, ?int $techId = null): array
    {
        global $DB;

        self::ensureTableExists();

        $where = [
            self::TABLE . '.data_hora_inicio' => ['>=', $startDateTime],
            [self::TABLE . '.data_hora_inicio' => ['<', $endDateTime]],
        ];
        if ($techId !== null && $techId > 0) {
            $where[self::TABLE . '.users_id_tech'] = $techId;
        }

        $iterator = $DB->request([
            'SELECT' => [
                self::TABLE . '.*',
                'glpi_tickets.id AS ticket_id',
                'glpi_tickets.name AS ticket_name',
            ],
            'FROM' => self::TABLE,
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        self::TABLE => 'tickets_id',
                        'glpi_tickets' => 'id',
                    ],
                ],
            ],
            'WHERE' => $where,
            'ORDER' => [self::TABLE . '.data_hora_inicio ASC', self::TABLE . '.id ASC'],
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getCalendarEvents(string $startDateTime, string $endDateTime, string $rootDoc, ?int $techId = null): array
    {
        $events = [];

        foreach (self::getForPeriod($startDateTime, $endDateTime, $techId) as $agendamento) {
            $start = self::normalizeDateTime((string) ($agendamento['data_hora_inicio'] ?? ''));
            if ($start === null) {
                continue;
            }

            $end = self::normalizeDateTime((string) ($agendamento['data_hora_fim'] ?? ''));
            if ($end === null) {
                $end = date('Y-m-d H:i:s', strtotime($start . ' +1 hour'));
            }

            $status = self::normalizeStatus((string) ($agendamento['status'] ?? self::STATUS_AGENDADO));
            $palette = self::getStatusPalette($status);
            $ticketId = (int) ($agendamento['ticket_id'] ?? $agendamento['tickets_id'] ?? 0);

            $events[] = [
                'id' => (string) ((int) ($agendamento['id'] ?? 0)),
                'title' => sprintf('#%d - %s', $ticketId, trim((string) ($agendamento['ticket_name'] ?? __('Sem título', 'agendamento')))),
                'start' => str_replace(' ', 'T', $start),
                'end' => str_replace(' ', 'T', $end),
                'allDay' => false,
                'backgroundColor' => $palette['background'],
                'borderColor' => $palette['border'],
                'textColor' => $palette['text'],
                'classNames' => ['plugin-agendamento-event', 'plugin-agendamento-event-' . $status],
                'extendedProps' => [
                    'status' => $status,
                    'tickets_id' => $ticketId,
                    'users_id_tech' => (int) ($agendamento['users_id_tech'] ?? 0),
                    'ticketUrl' => $ticketId > 0 ? $rootDoc . '/front/ticket.form.php?id=' . $ticketId : '',
                    'ticketTaskId' => (int) ($agendamento['tickettasks_id'] ?? 0),
                    'taskUrl' => (int) ($agendamento['tickettasks_id'] ?? 0) > 0 ? $rootDoc . '/front/tickettask.form.php?id=' . (int) $agendamento['tickettasks_id'] : '',
                    'technician' => (string) ($agendamento['tecnico_nome'] ?? '-'),
                    'clientContact' => trim((string) ($agendamento['contato_cliente'] ?? '')),
                    'clientAddress' => trim((string) ($agendamento['endereco_cliente'] ?? '')),
                    'statusLabel' => self::getStatusLabel($status),
                    'notes' => trim((string) ($agendamento['observacoes'] ?? '')),
                ],
            ];
        }

        return $events;
    }

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_AGENDADO => __('Agendado', 'agendamento'),
            self::STATUS_CONFIRMADO => __('Confirmado', 'agendamento'),
            self::STATUS_CANCELADO => __('Cancelado', 'agendamento'),
            self::STATUS_REALIZADO => __('Realizado', 'agendamento'),
        ];
    }

    public static function getForTechnician(int $userId, string $statusFilter = '', int $limit = 50): array
    {
        global $DB;

        self::ensureTableExists();

        $where = [self::TABLE . '.users_id_tech' => $userId];
        if ($statusFilter !== '' && in_array($statusFilter, [self::STATUS_AGENDADO, self::STATUS_CONFIRMADO, self::STATUS_CANCELADO, self::STATUS_REALIZADO], true)) {
            $where[self::TABLE . '.status'] = $statusFilter;
        }

        $iterator = $DB->request([
            'SELECT' => [
                self::TABLE . '.*',
                'glpi_tickets.id AS ticket_id',
                'glpi_tickets.name AS ticket_name',
            ],
            'FROM' => self::TABLE,
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        self::TABLE => 'tickets_id',
                        'glpi_tickets' => 'id',
                    ],
                ],
            ],
            'WHERE' => $where,
            'ORDER' => [self::TABLE . '.data_hora_inicio DESC'],
            'LIMIT' => $limit,
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function getAllAgendamentos(string $statusFilter = '', ?int $techId = null, int $limit = 100): array
    {
        global $DB;

        self::ensureTableExists();

        $where = [];
        if ($statusFilter !== '' && in_array($statusFilter, [self::STATUS_AGENDADO, self::STATUS_CONFIRMADO, self::STATUS_CANCELADO, self::STATUS_REALIZADO], true)) {
            $where[self::TABLE . '.status'] = $statusFilter;
        }
        if ($techId !== null && $techId > 0) {
            $where[self::TABLE . '.users_id_tech'] = $techId;
        }

        $iterator = $DB->request([
            'SELECT' => [
                self::TABLE . '.*',
                'glpi_tickets.id AS ticket_id',
                'glpi_tickets.name AS ticket_name',
            ],
            'FROM' => self::TABLE,
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        self::TABLE => 'tickets_id',
                        'glpi_tickets' => 'id',
                    ],
                ],
            ],
            'WHERE' => $where,
            'ORDER' => [self::TABLE . '.data_hora_inicio DESC'],
            'LIMIT' => $limit,
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function getTicketMetadata(int $ticketId): array
    {
        if ($ticketId <= 0) {
            return [
                'contact' => '',
                'address' => '',
            ];
        }

        $ticket = new GlpiTicket();
        if (!$ticket->getFromDB($ticketId)) {
            return [
                'contact' => '',
                'address' => '',
            ];
        }

        $requesters = $ticket->getUsers(1);
        $contact = '';
        $address = '';

        if (!empty($requesters)) {
            $firstRequester = reset($requesters);
            $userId = (int) ($firstRequester['users_id'] ?? 0);

            if ($userId > 0) {
                $user = new User();
                if ($user->getFromDB($userId)) {
                    $contact = trim((string) ($user->fields['mobile'] ?? ''));
                    if ($contact === '') {
                        $contact = trim((string) ($user->fields['phone'] ?? ''));
                    }
                    if ($contact === '') {
                        $contact = trim((string) ($user->fields['phone2'] ?? ''));
                    }

                    $addressParts = [];
                    foreach (['address', 'town', 'state', 'country'] as $field) {
                        if (!empty($user->fields[$field])) {
                            $addressParts[] = $user->fields[$field];
                        }
                    }
                    $address = implode(', ', $addressParts);
                }
            }
        }

        return [
            'contact' => $contact,
            'address' => $address,
        ];
    }

    public static function findAvailableSlots(int $technicianId, string $date, int $durationMinutes = 60, int $limit = 5): array
    {
        if ($technicianId <= 0) {
            throw new \RuntimeException(__('Selecione um técnico para buscar horários.', 'agendamento'));
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \RuntimeException(__('Data inválida para busca de horários.', 'agendamento'));
        }

        $durationMinutes = max(15, min(480, $durationMinutes));
        $config = Config::getConfig();
        $slotStepMinutes = self::timeStringToMinutes((string) ($config['slot_duration'] ?? '00:30:00'));
        if ($slotStepMinutes <= 0) {
            $slotStepMinutes = 30;
        }

        $day = date('Y-m-d', $timestamp);
        $dayStart = strtotime($day . ' ' . (string) ($config['slot_min_time'] ?? '07:00') . ':00');
        $dayEnd = strtotime($day . ' ' . (string) ($config['slot_max_time'] ?? '21:00') . ':00');
        if ($dayStart === false || $dayEnd === false || $dayEnd <= $dayStart) {
            throw new \RuntimeException(__('Configuração de expediente inválida.', 'agendamento'));
        }

        $now = time();
        if ($day === date('Y-m-d')) {
            $nextStep = (int) (ceil($now / ($slotStepMinutes * 60)) * ($slotStepMinutes * 60));
            $dayStart = max($dayStart, $nextStep);
        }

        $busyIntervals = [];
        foreach (self::getForPeriod(date('Y-m-d H:i:s', strtotime($day . ' 00:00:00')), date('Y-m-d H:i:s', strtotime($day . ' +1 day 00:00:00')), $technicianId) as $agendamento) {
            $status = self::normalizeStatus((string) ($agendamento['status'] ?? ''));
            if ($status === self::STATUS_CANCELADO) {
                continue;
            }

            $start = strtotime((string) ($agendamento['data_hora_inicio'] ?? ''));
            $end = strtotime((string) ($agendamento['data_hora_fim'] ?? ''));
            if ($start === false) {
                continue;
            }
            if ($end === false || $end <= $start) {
                $end = $start + ($durationMinutes * 60);
            }

            $busyIntervals[] = [
                'start' => max($start, $dayStart),
                'end' => min($end, $dayEnd),
            ];
        }

        usort($busyIntervals, static fn(array $left, array $right): int => $left['start'] <=> $right['start']);

        $merged = [];
        foreach ($busyIntervals as $interval) {
            if ($interval['end'] <= $interval['start']) {
                continue;
            }

            if ($merged === []) {
                $merged[] = $interval;
                continue;
            }

            $lastIndex = count($merged) - 1;
            if ($interval['start'] <= $merged[$lastIndex]['end']) {
                $merged[$lastIndex]['end'] = max($merged[$lastIndex]['end'], $interval['end']);
                continue;
            }

            $merged[] = $interval;
        }

        $slots = [];
        $cursor = $dayStart;
        foreach ($merged as $interval) {
            while (($cursor + ($durationMinutes * 60)) <= $interval['start']) {
                $slots[] = self::formatAvailableSlot($cursor, $durationMinutes);
                if (count($slots) >= $limit) {
                    return $slots;
                }
                $cursor += $slotStepMinutes * 60;
            }
            $cursor = max($cursor, $interval['end']);
        }

        while (($cursor + ($durationMinutes * 60)) <= $dayEnd) {
            $slots[] = self::formatAvailableSlot($cursor, $durationMinutes);
            if (count($slots) >= $limit) {
                break;
            }
            $cursor += $slotStepMinutes * 60;
        }

        return $slots;
    }

    public static function renderTicketCreateModal(GlpiTicket $ticket): void
    {
        global $CFG_GLPI;

        $ticketId = (int) $ticket->getID();
        if ($ticketId <= 0) {
            return;
        }

        $pluginConfig = Config::getConfig();
        $defaultDateTime = self::getDefaultDateTimeValues();
        $metadata = self::getTicketMetadata($ticketId);
        $ticketName = trim((string) ($ticket->fields['name'] ?? ''));
        $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        echo Html::css('lib/fullcalendar.css');
        echo Html::script('lib/fullcalendar.js');
        echo Html::script('lib/fullcalendar/core/locales/pt-br.js');
        $ticketCalendarConfig = [
            'actionsUrl' => $rootDoc . '/plugins/agendamento/front/agendamento_calendar.php',
            'locale' => 'pt-BR',
            'initialDate' => date('Y-m-d'),
            'slotMinTime' => ($pluginConfig['slot_min_time'] ?? '07:00') . ':00',
            'slotMaxTime' => ($pluginConfig['slot_max_time'] ?? '21:00') . ':00',
            'slotDuration' => $pluginConfig['slot_duration'] ?? '00:30:00',
            'calendarHeight' => 520,
            'texts' => [
                'today' => __('Hoje', 'agendamento'),
                'week' => __('Semana', 'agendamento'),
                'day' => __('Dia', 'agendamento'),
                'selectionHint' => __('Clique em um horário livre na agenda para preencher o agendamento.', 'agendamento'),
                'busyWarning' => __('Este horário já está ocupado.', 'agendamento'),
                'loadError' => __('Não foi possível carregar a agenda visual.', 'agendamento'),
                'missingTech' => __('Selecione um técnico antes de abrir a agenda.', 'agendamento'),
                'missingDate' => __('Selecione uma data para abrir a agenda.', 'agendamento'),
            ],
        ];

        echo "<div class='modal fade' id='plugin-agendamento-ticket-modal' tabindex='-1' aria-hidden='true'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-xl'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'>";
        echo "<h5 class='modal-title'><i class='ti ti-calendar-plus me-2'></i>" . htmlescape(__('Criar agendamento', 'agendamento')) . "</h5>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>";
        echo "</div>";
        echo "<form method='post' action='" . htmlescape($rootDoc . '/plugins/agendamento/front/ticket_agendamento.form.php') . "'>";
        echo "<div class='modal-body'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('agendamento_tickets_id', ['value' => $ticketId]);
        echo Html::hidden('ticket_redirect_id', ['value' => $ticketId]);

        echo "<div class='row g-3'>";
        echo "<div class='col-12'>";
        echo "<label class='form-label fw-semibold'>" . htmlescape(__('Chamado', 'agendamento')) . "</label>";
        echo "<div class='form-control-plaintext border rounded px-3 py-2 bg-light'>#" . $ticketId . ' - ' . htmlescape($ticketName !== '' ? $ticketName : __('Sem título', 'agendamento')) . "</div>";
        echo "</div>";

        echo "<div class='col-md-6'>";
        echo "<label class='form-label required'>" . htmlescape(__('Técnico', 'agendamento')) . "</label>";
        Dropdown::showFromArray('agendamento_users_id_tech', self::getTecnicosOptions(), [
            'value' => 0,
            'display_emptychoice' => true,
            'emptylabel' => __('Selecione um técnico...', 'agendamento'),
            'width' => '100%',
            'rand' => 4101,
        ]);
        echo "</div>";

        echo "<div class='col-md-6'>";
        echo "<label for='plugin-agendamento-ticket-duration' class='form-label'>" . htmlescape(__('Duração (minutos)', 'agendamento')) . "</label>";
        echo "<input type='number' id='plugin-agendamento-ticket-duration' name='agendamento_duration_minutes' class='form-control' min='15' max='480' step='15' value='" . (int) ($pluginConfig['default_event_duration'] ?? 60) . "'>";
        echo "</div>";

        echo "<div class='col-md-6'>";
        echo "<label for='plugin-agendamento-ticket-date' class='form-label'>" . htmlescape(__('Encontrar horário em', 'agendamento')) . "</label>";
        echo "<input type='date' id='plugin-agendamento-ticket-date' class='form-control' value='" . htmlescape(date('Y-m-d')) . "'>";
        echo "</div>";

        echo "<div class='col-md-6 d-flex align-items-end'>";
        echo "<button type='button' class='btn btn-outline-primary w-100' id='plugin-agendamento-find-slots' data-actions-url='" . htmlescape($rootDoc . '/plugins/agendamento/front/agendamento_calendar.php') . "'>";
        echo "<i class='ti ti-calendar-search me-1'></i>" . htmlescape(__('Abrir agenda visual', 'agendamento'));
        echo "</button>";
        echo "</div>";

        echo "<div class='col-12'>";
        echo "<div class='plugin-agendamento-ticket-picker rounded border p-3 bg-body-tertiary'>";
        echo "<div class='d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3'>";
        echo "<div>";
        echo "<div class='fw-semibold'>" . htmlescape(__('Agenda visual do técnico', 'agendamento')) . "</div>";
        echo "<div id='plugin-agendamento-ticket-selection-hint' class='small text-muted'>" . htmlescape(__('Escolha um técnico e uma data para navegar pela agenda.', 'agendamento')) . "</div>";
        echo "</div>";
        echo "<div id='plugin-agendamento-ticket-selection-badge' class='badge bg-primary-lt text-primary' hidden></div>";
        echo "</div>";
        echo "<div id='plugin-agendamento-slot-results' class='plugin-agendamento-slot-results mb-3' hidden></div>";
        echo "<div id='plugin-agendamento-ticket-calendar-shell' class='plugin-agendamento-ticket-calendar-shell' hidden>";
        echo "<div id='plugin-agendamento-ticket-calendar' class='plugin-agendamento-ticket-calendar' data-config='" . htmlescape(json_encode($ticketCalendarConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) . "'></div>";
        echo "</div>";
        echo "</div>";
        echo "</div>";

        echo "<div class='col-md-6'>";
        echo "<label for='plugin-agendamento-ticket-start' class='form-label required'>" . htmlescape(__('Data Início', 'agendamento')) . "</label>";
        echo "<input type='datetime-local' id='plugin-agendamento-ticket-start' name='agendamento_data_hora_inicio' class='form-control' required value='" . htmlescape($defaultDateTime['start']) . "'>";
        echo "</div>";

        echo "<div class='col-md-6'>";
        echo "<label for='plugin-agendamento-ticket-end' class='form-label'>" . htmlescape(__('Data Fim Prevista', 'agendamento')) . "</label>";
        echo "<input type='datetime-local' id='plugin-agendamento-ticket-end' name='agendamento_data_hora_fim' class='form-control' value='" . htmlescape($defaultDateTime['end']) . "'>";
        echo "</div>";

        echo "<div class='col-md-6'>";
        echo "<label for='plugin-agendamento-ticket-contact' class='form-label'>" . htmlescape(__('Contato do Cliente', 'agendamento')) . "</label>";
        echo "<input type='text' id='plugin-agendamento-ticket-contact' name='agendamento_contato_cliente' class='form-control' value='" . htmlescape($metadata['contact']) . "'>";
        echo "</div>";

        echo "<div class='col-md-6'>";
        echo "<label for='plugin-agendamento-ticket-status' class='form-label'>" . htmlescape(__('Status', 'agendamento')) . "</label>";
        echo "<select id='plugin-agendamento-ticket-status' name='agendamento_status' class='form-select'>";
        foreach (self::getStatusOptions() as $statusKey => $statusLabel) {
            $selected = $statusKey === self::STATUS_AGENDADO ? ' selected' : '';
            echo "<option value='" . htmlescape($statusKey) . "'" . $selected . ">" . htmlescape($statusLabel) . "</option>";
        }
        echo "</select>";
        echo "</div>";

        echo "<div class='col-12'>";
        echo "<label for='plugin-agendamento-ticket-address' class='form-label'>" . htmlescape(__('Endereço do Cliente', 'agendamento')) . "</label>";
        echo "<textarea id='plugin-agendamento-ticket-address' name='agendamento_endereco_cliente' class='form-control' rows='2'>" . htmlescape($metadata['address']) . "</textarea>";
        echo "</div>";

        echo "<div class='col-12'>";
        echo "<label for='plugin-agendamento-ticket-notes' class='form-label'>" . htmlescape(__('Observações', 'agendamento')) . "</label>";
        echo "<input type='text' id='plugin-agendamento-ticket-notes' name='agendamento_observacoes' class='form-control' value=''>";
        echo "</div>";
        echo "</div>";
        echo "</div>";

        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-outline-secondary' data-bs-dismiss='modal'>" . htmlescape(__('Cancelar', 'agendamento')) . "</button>";
        echo "<button type='submit' name='save_agendamento' value='1' class='btn btn-primary'>";
        echo "<i class='ti ti-device-floppy me-1'></i>" . htmlescape(__('Salvar agendamento', 'agendamento'));
        echo "</button>";
        echo "</div>";
        echo "</form>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
        echo Html::scriptBlock("if (typeof window.pluginAgendamentoBindTicketModal === 'function') { window.pluginAgendamentoBindTicketModal(); setTimeout(window.pluginAgendamentoBindTicketModal, 0); setTimeout(window.pluginAgendamentoBindTicketModal, 250); }");
    }

    public static function showMeusAgendamentos(): void
    {
        global $CFG_GLPI;

        self::ensureTableExists();

        $currentUserId = (int) Session::getLoginUserID();
        $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');

        $targetUserId = isset($_GET['tech_id']) ? (int) $_GET['tech_id'] : $currentUserId;
        if ($targetUserId <= 0) {
            $targetUserId = $currentUserId;
        }
        $isOwnView = ($targetUserId === $currentUserId);

        $targetUserName = '';
        if (!$isOwnView) {
            $user = new \User();
            if ($user->getFromDB($targetUserId)) {
                $name = trim(trim((string) ($user->fields['realname'] ?? '')) . ' ' . trim((string) ($user->fields['firstname'] ?? '')));
                $targetUserName = $name !== '' ? $name : trim((string) ($user->fields['name'] ?? ''));
            }
        }

        $statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
        $viewMode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : 'list';
        if (!in_array($viewMode, ['list', 'calendar'], true)) {
            $viewMode = 'list';
        }
        $statusOptions = self::getStatusOptions();

        $agendamentos = self::getForTechnician($targetUserId, $statusFilter);
        $counts = ['total' => 0, self::STATUS_AGENDADO => 0, self::STATUS_CONFIRMADO => 0, self::STATUS_CANCELADO => 0, self::STATUS_REALIZADO => 0];
        $allAgendamentos = self::getForTechnician($targetUserId, '', 500);
        foreach ($allAgendamentos as $ag) {
            $s = self::normalizeStatus((string) ($ag['status'] ?? ''));
            $counts['total']++;
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }

        $pluginUrl = $rootDoc . '/plugins/agendamento/front/meus_agendamentos.php';
        $baseQueryParams = $isOwnView ? [] : ['tech_id' => $targetUserId];

        $buildUrl = static function (array $extra = []) use ($pluginUrl, $baseQueryParams): string {
            $params = array_merge($baseQueryParams, $extra);
            return $pluginUrl . ($params !== [] ? '?' . http_build_query($params) : '');
        };

        $pageTitle = $isOwnView
            ? __('Meus Agendamentos', 'agendamento')
            : sprintf(__('Agendamentos de %s', 'agendamento'), $targetUserName);

        $pluginConfig = Config::getConfig();
        $calendarConfig = [
            'eventsUrl' => $rootDoc . '/plugins/agendamento/front/agendamento_calendar.php?action=events&tech_id=' . $targetUserId,
            'actionsUrl' => $rootDoc . '/plugins/agendamento/front/agendamento_calendar.php',
            'pageUrl' => $pluginUrl,
            'initialDate' => date('Y-m-d'),
            'initialView' => 'week',
            'locale' => 'pt-BR',
            'csrfToken' => Session::getNewCSRFToken(true),
            'ticketMetadata' => [],
            'slotMinTime' => ($pluginConfig['slot_min_time'] ?? '07:00') . ':00',
            'slotMaxTime' => ($pluginConfig['slot_max_time'] ?? '21:00') . ':00',
            'slotDuration' => $pluginConfig['slot_duration'] ?? '00:30:00',
            'calendarHeight' => (int) ($pluginConfig['calendar_height'] ?? 650),
            'defaultEventDuration' => (int) ($pluginConfig['default_event_duration'] ?? 60),
            'businessDays' => array_map('intval', explode(',', $pluginConfig['business_days'] ?? '1,2,3,4,5')),
            'filterTechId' => $targetUserId,
            'texts' => [
                'today' => __('Hoje', 'agendamento'),
                'month' => __('Mensal', 'agendamento'),
                'week' => __('Semanal', 'agendamento'),
                'day' => __('Diário', 'agendamento'),
                'saveError' => __('Não foi possível atualizar o agendamento.', 'agendamento'),
                'csrfError' => __('A sessão de segurança expirou. Recarregue a página e tente novamente.', 'agendamento'),
                'detailsTitle' => __('Detalhes do agendamento', 'agendamento'),
                'noNotes' => __('Sem observações informadas.', 'agendamento'),
                'noTechnician' => __('Não atribuído', 'agendamento'),
                'noClientContact' => __('Contato não informado.', 'agendamento'),
                'noClientAddress' => __('Endereço não informado.', 'agendamento'),
                'noTask' => __('Sem TicketTask vinculada.', 'agendamento'),
            ],
        ];
        $googleSyncEnabled = (int) ($pluginConfig['google_sync_enabled'] ?? 0) === 1
            && trim($pluginConfig['google_client_id'] ?? '') !== '';
        $googleConnected = $googleSyncEnabled && $isOwnView && GoogleCalendarAuth::isConnected($currentUserId);
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h3 class="card-title mb-0">
                    <i class="ti ti-calendar-user me-2"></i>
                    <?php echo htmlescape($pageTitle); ?>
                </h3>
                <div class="d-flex gap-2">
                    <?php if ($googleSyncEnabled && $isOwnView) { ?>
                        <?php if ($googleConnected) { ?>
                            <span class="badge bg-success-lt me-1 d-flex align-items-center">
                                <i class="ti ti-brand-google me-1"></i>
                                <?php echo htmlescape(__('Google Calendar conectado', 'agendamento')); ?>
                            </span>
                            <a href="<?php echo htmlescape($rootDoc . '/plugins/agendamento/front/google_action.php?action=sync&_glpi_csrf_token=' . urlencode(Session::getNewCSRFToken(true))); ?>" class="btn btn-sm btn-outline-success" title="<?php echo htmlescape(__('Sincronizar agora', 'agendamento')); ?>">
                                <i class="ti ti-refresh me-1"></i><?php echo htmlescape(__('Sincronizar', 'agendamento')); ?>
                            </a>
                            <a href="<?php echo htmlescape($rootDoc . '/plugins/agendamento/front/google_action.php?action=disconnect&_glpi_csrf_token=' . urlencode(Session::getNewCSRFToken(true))); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('<?php echo htmlescape(__('Deseja desconectar o Google Calendar?', 'agendamento')); ?>');">
                                <i class="ti ti-unlink me-1"></i><?php echo htmlescape(__('Desconectar', 'agendamento')); ?>
                            </a>
                        <?php } else { ?>
                            <a href="<?php echo htmlescape($rootDoc . '/plugins/agendamento/front/google_action.php?action=connect&_glpi_csrf_token=' . urlencode(Session::getNewCSRFToken(true))); ?>" class="btn btn-sm btn-google-connect">
                                <i class="ti ti-brand-google me-1"></i><?php echo htmlescape(__('Conectar Google Calendar', 'agendamento')); ?>
                            </a>
                        <?php } ?>
                    <?php } ?>
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="<?php echo htmlescape($buildUrl(['mode' => 'list', 'status' => $statusFilter])); ?>" class="btn <?php echo $viewMode === 'list' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <i class="ti ti-list me-1"></i><?php echo htmlescape(__('Lista', 'agendamento')); ?>
                        </a>
                        <a href="<?php echo htmlescape($buildUrl(['mode' => 'calendar', 'status' => $statusFilter])); ?>" class="btn <?php echo $viewMode === 'calendar' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <i class="ti ti-calendar me-1"></i><?php echo htmlescape(__('Calendário', 'agendamento')); ?>
                        </a>
                    </div>
                    <a href="<?php echo htmlescape($rootDoc . '/plugins/agendamento/front/agendamento.php'); ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="ti ti-calendar-event me-1"></i><?php echo htmlescape(__('Agenda Geral', 'agendamento')); ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <?php
            $badges = [
                '' => ['label' => __('Todos', 'agendamento'), 'count' => $counts['total'], 'color' => 'primary'],
                self::STATUS_AGENDADO => ['label' => __('Agendados', 'agendamento'), 'count' => $counts[self::STATUS_AGENDADO], 'color' => 'info'],
                self::STATUS_CONFIRMADO => ['label' => __('Confirmados', 'agendamento'), 'count' => $counts[self::STATUS_CONFIRMADO], 'color' => 'success'],
                self::STATUS_REALIZADO => ['label' => __('Realizados', 'agendamento'), 'count' => $counts[self::STATUS_REALIZADO], 'color' => 'secondary'],
                self::STATUS_CANCELADO => ['label' => __('Cancelados', 'agendamento'), 'count' => $counts[self::STATUS_CANCELADO], 'color' => 'danger'],
            ];
            foreach ($badges as $filterKey => $badge) {
                $active = $statusFilter === $filterKey ? ' active' : '';
                $badgeParams = ['mode' => $viewMode];
                if ($filterKey !== '') {
                    $badgeParams['status'] = $filterKey;
                }
                echo "<div class='col-auto'>";
                echo "<a href='" . htmlescape($buildUrl($badgeParams)) . "' class='btn btn-outline-" . $badge['color'] . $active . "'>";
                echo htmlescape($badge['label']) . " <span class='badge bg-" . $badge['color'] . " ms-1'>" . $badge['count'] . "</span>";
                echo "</a></div>";
            }
            ?>
        </div>

        <?php if ($viewMode === 'calendar') { ?>
            <div class="card">
                <div class="card-body">
                    <div id="plugin-agendamento-tech-calendar"
                         class="plugin-agendamento-calendar"
                         data-config='<?php echo htmlescape(json_encode($calendarConfig, JSON_THROW_ON_ERROR)); ?>'
                    ></div>
                </div>
            </div>
            <?php
            $calendarJs = @file_get_contents(\Plugin::getPhpDir('agendamento') . '/public/js/agendamento-tech-calendar.js');
            if ($calendarJs !== false && trim($calendarJs) !== '') {
                echo "<script>\n" . $calendarJs . "\n</script>";
            }
            ?>
        <?php } else { ?>
            <?php if ($agendamentos === []) { ?>
                <div class="alert alert-info">
                    <i class="ti ti-info-circle me-1"></i>
                    <?php echo htmlescape(__('Nenhum agendamento encontrado.', 'agendamento')); ?>
                </div>
            <?php } else { ?>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-vcenter table-hover card-table">
                            <thead>
                                <tr>
                                    <th><?php echo htmlescape(__('Chamado', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Título', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Início', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Fim', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Status', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Contato', 'agendamento')); ?></th>
                                    <th><?php echo htmlescape(__('Observações', 'agendamento')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agendamentos as $ag) {
                                    $ticketId = (int) ($ag['ticket_id'] ?? $ag['tickets_id'] ?? 0);
                                    $status = self::normalizeStatus((string) ($ag['status'] ?? ''));
                                    $palette = self::getStatusPalette($status);
                                    $startAt = strtotime((string) ($ag['data_hora_inicio'] ?? ''));
                                    $endAt = strtotime((string) ($ag['data_hora_fim'] ?? ''));
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo htmlescape($rootDoc . '/front/ticket.form.php?id=' . $ticketId); ?>">
                                            #<?php echo $ticketId; ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlescape((string) ($ag['ticket_name'] ?? __('Sem título', 'agendamento'))); ?></td>
                                    <td><?php echo $startAt !== false ? date('d/m/Y H:i', $startAt) : '-'; ?></td>
                                    <td><?php echo $endAt !== false ? date('d/m/Y H:i', $endAt) : '-'; ?></td>
                                    <td>
                                        <span class="badge" style="background-color:<?php echo htmlescape($palette['background']); ?>;color:<?php echo htmlescape($palette['text']); ?>;border:1px solid <?php echo htmlescape($palette['border']); ?>">
                                            <?php echo htmlescape(self::getStatusLabel($status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlescape(trim((string) ($ag['contato_cliente'] ?? '')) ?: '-'); ?></td>
                                    <td><?php echo htmlescape(mb_strimwidth(trim((string) ($ag['observacoes'] ?? '')), 0, 60, '...') ?: '-'); ?></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php } ?>
        <?php }
    }

    public static function getStatusLabel(string $status): string
    {
        $options = self::getStatusOptions();
        $status = self::normalizeStatus($status);
        return $options[$status] ?? $options[self::STATUS_AGENDADO];
    }

    private static function renderKpiCard(string $label, string $value): string
    {
        return "<article class='plugin-agendamento-kpi'><span>" . htmlescape($label) . "</span><strong>" . htmlescape($value) . "</strong></article>";
    }

    private static function renderAgendaCard(array $agendamento, string $currentDate, string $view, string $baseUrl, string $rootDoc, bool $withActions): void
    {
        $ticketId = (int) ($agendamento['ticket_id'] ?? $agendamento['tickets_id'] ?? 0);
        $status = self::normalizeStatus((string) ($agendamento['status'] ?? self::STATUS_AGENDADO));
        $startAt = strtotime((string) ($agendamento['data_hora_inicio'] ?? ''));
        $endAt = strtotime((string) ($agendamento['data_hora_fim'] ?? ''));
        $periodLabel = $startAt !== false ? date('H:i', $startAt) : '--:--';
        if ($endAt !== false) {
            $periodLabel .= ' - ' . date('H:i', $endAt);
        }

        echo "<article class='plugin-agendamento-card plugin-agendamento-status-" . htmlescape($status) . "'>";
        echo "<div class='plugin-agendamento-card-top'><strong>" . htmlescape($periodLabel) . "</strong><span>" . htmlescape(self::getStatusLabel($status)) . "</span></div>";
        echo "<a href='" . htmlescape($rootDoc . '/front/ticket.form.php?id=' . $ticketId) . "'>#" . $ticketId . "</a>";
        echo "<div>" . htmlescape((string) ($agendamento['ticket_name'] ?? '')) . "</div>";
        echo "<div>" . htmlescape((string) ($agendamento['tecnico_nome'] ?? '-')) . "</div>";
        if (trim((string) ($agendamento['observacoes'] ?? '')) !== '') {
            echo "<div>" . nl2br(htmlescape((string) $agendamento['observacoes'])) . "</div>";
        }
        if ($withActions) {
            echo "<form method='post' action='" . htmlescape(self::buildPageUrl($baseUrl, $currentDate, $view)) . "' class='plugin-agendamento-card-actions'>";
            echo "<input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'>";
            echo "<input type='hidden' name='date' value='" . htmlescape($currentDate) . "'>";
            echo "<input type='hidden' name='view' value='" . htmlescape($view) . "'>";
            echo "<input type='hidden' name='tickets_id' value='" . $ticketId . "'>";
            echo "<input type='hidden' name='agendamento_id' value='" . (int) ($agendamento['id'] ?? 0) . "'>";
            foreach ([self::STATUS_CONFIRMADO => __('Confirmar', 'agendamento'), self::STATUS_REALIZADO => __('Concluir', 'agendamento'), self::STATUS_CANCELADO => __('Cancelar', 'agendamento')] as $action => $label) {
                if ($action === $status) {
                    continue;
                }
                echo "<button type='submit' name='update_agendamento_status' value='" . htmlescape($action) . "' class='btn btn-sm btn-outline-primary'>" . htmlescape($label) . "</button>";
            }
            echo "</form>";
        }
        echo "</article>";
    }

    private static function syncGoogleCalendar(int $agendamentoId): void
    {
        global $DB;

        try {
            $agendamento = self::getById($agendamentoId);
            if ($agendamento === null) {
                return;
            }

            $techUserId = (int) ($agendamento['users_id_tech'] ?? 0);
            if ($techUserId <= 0 || !GoogleCalendarAuth::isConnected($techUserId)) {
                return;
            }

            $googleEventId = GoogleCalendarSync::syncAgendamento($agendamento, $techUserId);
            if ($googleEventId !== null && empty($agendamento['google_event_id'])) {
                $DB->update(self::TABLE, [
                    'google_event_id' => $googleEventId,
                ], [
                    'id' => $agendamentoId,
                ]);
            }
        } catch (\Throwable $e) {
            \Toolbox::logInFile('agendamento', "Google Calendar sync error for #{$agendamentoId}: " . $e->getMessage());
        }
    }

    private static function deleteGoogleCalendarEvent(int $agendamentoId): void
    {
        global $DB;

        try {
            $agendamento = self::getById($agendamentoId);
            if ($agendamento === null) {
                return;
            }

            $googleEventId = $agendamento['google_event_id'] ?? '';
            $techUserId = (int) ($agendamento['users_id_tech'] ?? 0);

            if ($googleEventId === '' || $techUserId <= 0) {
                return;
            }

            GoogleCalendarSync::deleteEvent($techUserId, $googleEventId);

            $DB->update(self::TABLE, [
                'google_event_id' => null,
            ], [
                'id' => $agendamentoId,
            ]);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('agendamento', "Google Calendar delete error for #{$agendamentoId}: " . $e->getMessage());
        }
    }

    private static function syncLinkedTask(int $agendamentoId): void
    {
        global $DB;

        $agendamento = self::getById($agendamentoId);
        if ($agendamento === null) {
            throw new \RuntimeException(__('Agendamento não encontrado.', 'agendamento'));
        }

        $taskId = (int) ($agendamento['tickettasks_id'] ?? 0);
        if ($taskId > 0) {
            $task = new \TicketTask();
            if ($task->getFromDB($taskId)) {
                $payload = self::buildTaskPayload($agendamento);
                $payload['id'] = $taskId;
                if (!$task->update($payload)) {
                    throw new \RuntimeException(__('Falha ao atualizar TicketTask vinculada.', 'agendamento'));
                }
                return;
            }
        }

        $task = new \TicketTask();
        $newTaskId = $task->add(self::buildTaskPayload($agendamento));
        if ($newTaskId <= 0) {
            throw new \RuntimeException(__('Falha ao criar TicketTask vinculada.', 'agendamento'));
        }

        $DB->update(self::TABLE, ['tickettasks_id' => (int) $newTaskId], ['id' => $agendamentoId]);
    }

    private static function registerCancellationFollowup(int $ticketId, array $agendamento, string $reason): void
    {
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            throw new \RuntimeException(__('Chamado do agendamento não encontrado.', 'agendamento'));
        }

        $followup = new \ITILFollowup();
        $followupId = $followup->add([
            'itemtype' => 'Ticket',
            'items_id' => $ticketId,
            'is_private' => 1,
            'content' => self::buildCancellationFollowupContent($agendamento, $reason),
        ]);

        if ($followupId <= 0) {
            throw new \RuntimeException(__('Falha ao registrar o motivo do cancelamento no chamado.', 'agendamento'));
        }
    }

    private static function buildCancellationFollowupContent(array $agendamento, string $reason): string
    {
        $lines = [
            __('Agendamento cancelado pelo plugin de agenda.', 'agendamento'),
            sprintf(__('Motivo do cancelamento: %s', 'agendamento'), trim($reason)),
            sprintf(__('Técnico: %s', 'agendamento'), (string) ($agendamento['tecnico_nome'] ?? '-')),
            sprintf(__('Início previsto: %s', 'agendamento'), self::formatDateTimeLabel((string) ($agendamento['data_hora_inicio'] ?? ''))),
        ];

        $end = trim((string) ($agendamento['data_hora_fim'] ?? ''));
        if ($end !== '') {
            $lines[] = sprintf(__('Fim previsto: %s', 'agendamento'), self::formatDateTimeLabel($end));
        }

        return implode("\n", $lines);
    }

    private static function registerRescheduleFollowup(int $ticketId, array $agendamento, string $novaInicio, ?string $novaFim, string $motivo): void
    {
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return;
        }

        $followup = new \ITILFollowup();
        $followupId = $followup->add([
            'itemtype' => 'Ticket',
            'items_id' => $ticketId,
            'is_private' => 1,
            'content' => self::buildRescheduleFollowupContent($agendamento, $novaInicio, $novaFim, $motivo),
        ]);

        if ($followupId <= 0) {
            throw new \RuntimeException(__('Falha ao registrar o motivo do reagendamento no chamado.', 'agendamento'));
        }
    }

    private static function buildRescheduleFollowupContent(array $agendamento, string $novaInicio, ?string $novaFim, string $motivo): string
    {
        $linhas = [
            __('🔄 Agendamento reagendado pelo plugin de agenda.', 'agendamento'),
            sprintf(__('Motivo: %s', 'agendamento'), $motivo),
            sprintf(__('Técnico: %s', 'agendamento'), (string) ($agendamento['tecnico_nome'] ?? '-')),
            sprintf(__('Data/hora anterior: %s', 'agendamento'), self::formatDateTimeLabel((string) ($agendamento['data_hora_inicio'] ?? ''))),
            sprintf(__('Nova data/hora: %s', 'agendamento'), self::formatDateTimeLabel($novaInicio)),
        ];

        if ($novaFim !== null) {
            $linhas[] = sprintf(__('Novo fim: %s', 'agendamento'), self::formatDateTimeLabel($novaFim));
        }

        return implode("\n", $linhas);
    }

    private static function buildTaskPayload(array $agendamento): array
    {
        global $DB;

        $status = self::normalizeStatus((string) ($agendamento['status'] ?? self::STATUS_AGENDADO));
        $begin = self::normalizeDateTime((string) ($agendamento['data_hora_inicio'] ?? '')) ?? $_SESSION['glpi_currenttime'];
        $end = self::normalizeDateTime((string) ($agendamento['data_hora_fim'] ?? '')) ?? date('Y-m-d H:i:s', strtotime($begin . ' +1 hour'));

        $payload = [
            'tickets_id' => (int) ($agendamento['tickets_id'] ?? 0),
            'content' => self::buildTaskContent($agendamento),
            'users_id' => (int) ($agendamento['users_id'] ?? Session::getLoginUserID()),
            'users_id_tech' => (int) ($agendamento['users_id_tech'] ?? 0),
            'state' => self::getLinkedTaskState($status),
            'is_private' => 0,
            'actiontime' => max(0, strtotime($end) - strtotime($begin)),
            'plan' => [
                'begin' => $begin,
                'end' => $end,
            ],
        ];

        if ($DB->fieldExists('glpi_tickettasks', 'percent_done')) {
            $payload['percent_done'] = $payload['state'] === \Planning::DONE ? 100 : 0;
        }

        return $payload;
    }

    private static function getLinkedTaskState(string $status): int
    {
        return match (self::normalizeStatus($status)) {
            self::STATUS_REALIZADO => \Planning::DONE,
            self::STATUS_CANCELADO => \Planning::INFO,
            default => \Planning::TODO,
        };
    }

    private static function buildTaskContent(array $agendamento): string
    {
        $lines = [
            __('Agendamento criado pelo plugin independente de agenda.', 'agendamento'),
            sprintf(__('Técnico: %s', 'agendamento'), (string) ($agendamento['tecnico_nome'] ?? '-')),
            sprintf(__('Contato do cliente: %s', 'agendamento'), (string) ($agendamento['contato_cliente'] ?? '-')),
            sprintf(__('Endereço do cliente: %s', 'agendamento'), (string) ($agendamento['endereco_cliente'] ?? '-')),
            sprintf(__('Início: %s', 'agendamento'), self::formatDateTimeLabel((string) ($agendamento['data_hora_inicio'] ?? ''))),
            sprintf(__('Fim: %s', 'agendamento'), self::formatDateTimeLabel((string) ($agendamento['data_hora_fim'] ?? ''))),
            sprintf(__('Status: %s', 'agendamento'), self::getStatusLabel((string) ($agendamento['status'] ?? self::STATUS_AGENDADO))),
        ];

        $notes = trim((string) ($agendamento['observacoes'] ?? ''));
        if ($notes !== '') {
            $lines[] = sprintf(__('Observações: %s', 'agendamento'), $notes);
        }

        return implode("\n", $lines);
    }

    private static function getById(int $agendamentoId): ?array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['id' => $agendamentoId],
            'LIMIT' => 1,
        ]);

        return count($iterator) > 0 ? $iterator->current() : null;
    }

    private static function getTicketOptions(): array
    {
        global $DB;

        $options = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM' => 'glpi_tickets',
            'WHERE' => ['is_deleted' => 0],
            'ORDER' => ['date_mod DESC'],
            'LIMIT' => 150,
        ]);

        foreach ($iterator as $ticket) {
            $ticketId = (int) ($ticket['id'] ?? 0);
            if ($ticketId <= 0) {
                continue;
            }
            $options[(string) $ticketId] = sprintf('#%d - %s', $ticketId, trim((string) ($ticket['name'] ?? '')) ?: __('Sem título', 'agendamento'));
        }

        return $options;
    }

    private static function getTicketMetadataMap(array $ticketIds): array
    {
        global $DB;

        $ticketIds = array_values(array_filter(array_map('intval', $ticketIds), static fn (int $ticketId): bool => $ticketId > 0));
        if ($ticketIds === []) {
            return [];
        }

        $select = [
            'glpi_tickets.id AS ticket_id',
            'glpi_locations.address AS location_address',
            'glpi_locations.postcode AS location_postcode',
            'glpi_locations.town AS location_town',
            'glpi_locations.state AS location_state',
            'glpi_locations.country AS location_country',
            'glpi_users.name AS requester_name',
            'glpi_users.realname AS requester_realname',
            'glpi_users.firstname AS requester_firstname',
        ];

        foreach ([
            'phone' => 'requester_phone',
            'phone2' => 'requester_phone2',
            'mobile' => 'requester_mobile',
            'email' => 'requester_email',
        ] as $column => $alias) {
            if ($DB->fieldExists('glpi_users', $column)) {
                $select[] = 'glpi_users.' . $column . ' AS ' . $alias;
            }
        }

        $metadata = [];
        $iterator = $DB->request([
            'SELECT' => $select,
            'FROM' => 'glpi_tickets',
            'LEFT JOIN' => [
                'glpi_locations' => [
                    'ON' => [
                        'glpi_tickets' => 'locations_id',
                        'glpi_locations' => 'id',
                    ],
                ],
                'glpi_tickets_users' => [
                    'ON' => [
                        'glpi_tickets' => 'id',
                        'glpi_tickets_users' => 'tickets_id',
                        [
                            'AND' => [
                                'glpi_tickets_users.type' => \CommonITILActor::REQUESTER,
                            ],
                        ],
                    ],
                ],
                'glpi_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'users_id',
                        'glpi_users' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_tickets.id' => $ticketIds,
            ],
            'ORDER' => [
                'glpi_tickets.id ASC',
                'glpi_tickets_users.id ASC',
            ],
        ]);

        foreach ($iterator as $row) {
            $ticketId = (string) ((int) ($row['ticket_id'] ?? 0));
            if ($ticketId === '0') {
                continue;
            }

            if (!isset($metadata[$ticketId])) {
                $metadata[$ticketId] = [
                    'contact' => '',
                    'address' => self::buildTicketAddress($row),
                ];
            }

            $contact = self::buildRequesterContact($row);
            if ($contact !== '') {
                $existing = $metadata[$ticketId]['contact'] === '' ? [] : explode(' | ', $metadata[$ticketId]['contact']);
                if (!in_array($contact, $existing, true)) {
                    $existing[] = $contact;
                    $metadata[$ticketId]['contact'] = implode(' | ', array_filter($existing));
                }
            }
        }

        return $metadata;
    }

    private static function getTecnicosOptions(): array
    {
        global $DB;

        $profileIds = Config::getTechnicianProfileIds();
        $options = [];
        $request = [
            'SELECT' => ['id', 'name', 'realname', 'firstname'],
            'FROM' => 'glpi_users',
            'WHERE' => ['glpi_users.is_deleted' => 0],
            'ORDER' => ['realname ASC', 'firstname ASC', 'name ASC'],
            'LIMIT' => 300,
        ];

        if ($profileIds !== []) {
            $request['LEFT JOIN'] = [
                'glpi_profiles_users' => [
                    'ON' => [
                        'glpi_users' => 'id',
                        'glpi_profiles_users' => 'users_id',
                    ],
                ],
            ];
            $request['WHERE']['glpi_profiles_users.profiles_id'] = $profileIds;
        }

        $iterator = $DB->request($request);

        foreach ($iterator as $user) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $label = trim(trim((string) ($user['realname'] ?? '')) . ' ' . trim((string) ($user['firstname'] ?? '')));
            if ($label === '') {
                $label = trim((string) ($user['name'] ?? ''));
            }
            if ($label === '') {
                continue;
            }
            $options[(string) $userId] = $label;
        }

        return $options;
    }

    private static function resolveTechnicianName(int $userId): string
    {
        $options = self::getTecnicosOptions();
        return $options[(string) $userId] ?? '';
    }

    private static function prepareFormData(array $data): array
    {
        $ticketId = (int) ($data['agendamento_tickets_id'] ?? 0);
        $technicianId = (int) ($data['agendamento_users_id_tech'] ?? 0);
        $start = self::normalizeDateTime((string) ($data['agendamento_data_hora_inicio'] ?? ''));
        $end = self::normalizeDateTime((string) ($data['agendamento_data_hora_fim'] ?? ''));
        $status = self::normalizeStatus((string) ($data['agendamento_status'] ?? self::STATUS_AGENDADO));
        $notes = self::nullableString($data['agendamento_observacoes'] ?? null);

        if ($ticketId <= 0) {
            throw new \RuntimeException(__('Selecione um chamado.', 'agendamento'));
        }

        if ($technicianId <= 0) {
            throw new \RuntimeException(__('Selecione um técnico.', 'agendamento'));
        }

        if ($start === null) {
            throw new \RuntimeException(__('Informe a data inicial do agendamento.', 'agendamento'));
        }

        if ($end !== null && strtotime($end) < strtotime($start)) {
            throw new \RuntimeException(__('A data final deve ser maior ou igual à data inicial.', 'agendamento'));
        }

        $ticket = new GlpiTicket();
        if (!$ticket->getFromDB($ticketId)) {
            throw new \RuntimeException(__('Chamado não encontrado.', 'agendamento'));
        }

        $techName = self::resolveTechnicianName($technicianId);
        if ($techName === '') {
            throw new \RuntimeException(__('Técnico não encontrado.', 'agendamento'));
        }

        return [
            'tickets_id' => $ticketId,
            'users_id_tech' => $technicianId,
            'tecnico_nome' => $techName,
            'contato_cliente' => self::nullableString($data['agendamento_contato_cliente'] ?? null),
            'endereco_cliente' => self::nullableString($data['agendamento_endereco_cliente'] ?? null),
            'data_hora_inicio' => $start,
            'data_hora_fim' => $end,
            'status' => $status,
            'observacoes' => $notes,
            'users_id' => (int) Session::getLoginUserID(),
        ];
    }

    private static function getDefaultDateTimeValues(): array
    {
        $now = time();
        $start = (int) ceil($now / 3600) * 3600;
        return [
            'start' => date('Y-m-d\TH:i', $start),
            'end' => date('Y-m-d\TH:i', $start + 3600),
        ];
    }

    private static function ensureTableExists(): void
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            if (!$DB->fieldExists(self::TABLE, 'contato_cliente')) {
                $DB->doQuery("ALTER TABLE `" . self::TABLE . "` ADD COLUMN `contato_cliente` varchar(255) DEFAULT NULL AFTER `tecnico_nome`");
            }
            if (!$DB->fieldExists(self::TABLE, 'endereco_cliente')) {
                $DB->doQuery("ALTER TABLE `" . self::TABLE . "` ADD COLUMN `endereco_cliente` text DEFAULT NULL AFTER `contato_cliente`");
            }
            return;
        }

        $defaultCharset = DBConnection::getDefaultCharset();
        $defaultCollation = DBConnection::getDefaultCollation();
        $defaultKeySign = DBConnection::getDefaultPrimaryKeySignOption();

        $DB->doQuery("CREATE TABLE `" . self::TABLE . "` (
            `id` int " . $defaultKeySign . " NOT NULL AUTO_INCREMENT,
            `tickets_id` int " . $defaultKeySign . " NOT NULL DEFAULT 0,
            `users_id_tech` int " . $defaultKeySign . " NOT NULL DEFAULT 0,
            `tecnico_nome` varchar(255) DEFAULT NULL,
            `contato_cliente` varchar(255) DEFAULT NULL,
            `endereco_cliente` text DEFAULT NULL,
            `data_hora_inicio` datetime NOT NULL,
            `data_hora_fim` datetime DEFAULT NULL,
            `status` varchar(50) NOT NULL DEFAULT 'agendado',
            `observacoes` text DEFAULT NULL,
            `users_id` int " . $defaultKeySign . " NOT NULL DEFAULT 0,
            `tickettasks_id` int " . $defaultKeySign . " NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_plugin_agendamento_ticket` (`tickets_id`),
            KEY `idx_plugin_agendamento_inicio` (`data_hora_inicio`),
            KEY `idx_plugin_agendamento_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=" . $defaultCharset . " COLLATE=" . $defaultCollation);
    }

    private static function getStatusPalette(string $status): array
    {
        return match (self::normalizeStatus($status)) {
            self::STATUS_CONFIRMADO => ['background' => '#f59e0b', 'border' => '#f59e0b', 'text' => '#ffffff'],
            self::STATUS_CANCELADO => ['background' => '#fee2e2', 'border' => '#dc2626', 'text' => '#991b1b'],
            self::STATUS_REALIZADO => ['background' => '#10b981', 'border' => '#10b981', 'text' => '#ffffff'],
            default => ['background' => '#3b82f6', 'border' => '#3b82f6', 'text' => '#ffffff'],
        };
    }

    private static function buildPageUrl(string $baseUrl, string $date, string $view): string
    {
        return $baseUrl . '?date=' . rawurlencode($date) . '&view=' . rawurlencode($view);
    }

    private static function timeStringToMinutes(string $value): int
    {
        $parts = explode(':', $value);
        if (count($parts) < 2) {
            return 0;
        }

        return ((int) $parts[0] * 60) + (int) $parts[1];
    }

    private static function formatAvailableSlot(int $startTimestamp, int $durationMinutes): array
    {
        $endTimestamp = $startTimestamp + ($durationMinutes * 60);

        return [
            'start' => date('Y-m-d\TH:i', $startTimestamp),
            'end' => date('Y-m-d\TH:i', $endTimestamp),
            'label' => date('d/m/Y H:i', $startTimestamp) . ' - ' . date('H:i', $endTimestamp),
        ];
    }

    private static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $status = match ($status) {
            'concluido', 'concluído', 'feito' => self::STATUS_REALIZADO,
            default => $status,
        };
        return array_key_exists($status, self::getStatusOptions()) ? $status : self::STATUS_AGENDADO;
    }

    private static function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private static function nullableString($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private static function formatDateTimeLabel(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? '-' : date('d/m/Y H:i', $timestamp);
    }

    private static function normalizeView(string $view): string
    {
        $view = strtolower(trim($view));
        return in_array($view, ['day', 'week', 'month'], true) ? $view : 'week';
    }

    private static function getPeriodWindow(?string $anchorDate, string $view): array
    {
        $anchor = trim((string) ($anchorDate ?? '')) !== '' ? new DateTimeImmutable((string) $anchorDate) : new DateTimeImmutable('today');
        $anchor = $anchor->setTime(0, 0, 0);

        if ($view === 'day') {
            $start = $anchor;
            $end = $start->add(new DateInterval('P1D'));
        } elseif ($view === 'month') {
            $start = $anchor->modify('first day of this month')->setTime(0, 0, 0);
            $end = $start->modify('first day of next month')->setTime(0, 0, 0);
        } else {
            $start = $anchor->modify('monday this week')->setTime(0, 0, 0);
            $end = $start->add(new DateInterval('P7D'));
        }

        return [
            'anchor' => $anchor,
            'start' => $start,
            'end' => $end,
        ];
    }

    private static function buildRequesterContact(array $row): string
    {
        $name = trim(trim((string) ($row['requester_realname'] ?? '')) . ' ' . trim((string) ($row['requester_firstname'] ?? '')));
        if ($name === '') {
            $name = trim((string) ($row['requester_name'] ?? ''));
        }

        $channels = array_values(array_filter([
            trim((string) ($row['requester_mobile'] ?? '')),
            trim((string) ($row['requester_phone'] ?? '')),
            trim((string) ($row['requester_phone2'] ?? '')),
            trim((string) ($row['requester_email'] ?? '')),
        ]));

        if ($name === '' && $channels === []) {
            return '';
        }

        if ($channels === []) {
            return $name;
        }

        return trim($name !== '' ? ($name . ' - ' . $channels[0]) : $channels[0]);
    }

    private static function buildTicketAddress(array $row): string
    {
        $parts = array_filter([
            trim((string) ($row['location_address'] ?? '')),
            trim((string) ($row['location_postcode'] ?? '')),
            trim((string) ($row['location_town'] ?? '')),
            trim((string) ($row['location_state'] ?? '')),
            trim((string) ($row['location_country'] ?? '')),
        ]);

        return implode(', ', $parts);
    }

    public static function showCentralWidget(): void
    {
        global $CFG_GLPI, $DB;

        $userId = (int) Session::getLoginUserID();
        if ($userId <= 0) {
            return;
        }

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }

        $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        $todayStart = date('Y-m-d 00:00:00');
        $weekEnd = date('Y-m-d 23:59:59', strtotime('+7 days'));

        $iterator = $DB->request([
            'SELECT' => [
                self::TABLE . '.*',
                'glpi_tickets.name AS ticket_name',
            ],
            'FROM' => self::TABLE,
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        self::TABLE => 'tickets_id',
                        'glpi_tickets' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                self::TABLE . '.users_id_tech' => $userId,
                self::TABLE . '.status' => [self::STATUS_AGENDADO, self::STATUS_CONFIRMADO],
                self::TABLE . '.data_hora_inicio' => ['>=', $todayStart],
                [self::TABLE . '.data_hora_inicio' => ['<=', $weekEnd]],
            ],
            'ORDER' => [self::TABLE . '.data_hora_inicio ASC'],
            'LIMIT' => 10,
        ]);

        $agendamentos = [];
        foreach ($iterator as $row) {
            $agendamentos[] = $row;
        }

        $countAll = 0;
        $countIterator = $DB->request([
            'SELECT' => ['COUNT' => 'id AS total'],
            'FROM' => self::TABLE,
            'WHERE' => [
                'users_id_tech' => $userId,
                'status' => [self::STATUS_AGENDADO, self::STATUS_CONFIRMADO],
                'data_hora_inicio' => ['>=', $todayStart],
            ],
        ]);
        foreach ($countIterator as $r) {
            $countAll = (int) ($r['total'] ?? 0);
        }

        $todayStr = date('Y-m-d');
        $tomorrowStr = date('Y-m-d', strtotime('+1 day'));
        $meusUrl = $rootDoc . '/plugins/agendamento/front/meus_agendamentos.php';
        $agendaUrl = $rootDoc . '/plugins/agendamento/front/agendamento.php';
        ?>
        <tr><td colspan="2" style="padding: 0;">
        <div class="card mb-4 shadow-sm" id="plugin-agendamento-central-widget">
            <div class="card-header border-bottom" style="padding: 1rem 1.25rem;">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h3 class="card-title mb-0" style="font-size: 1.1rem; font-weight: 600;">
                        <i class="ti ti-calendar-event me-2 text-primary"></i>
                        <?php echo __('Minha Agenda', 'agendamento'); ?>
                        <?php if ($countAll > 0) { ?>
                            <span class="badge bg-primary ms-2" style="font-size: 0.75rem;"><?php echo $countAll; ?></span>
                        <?php } ?>
                    </h3>
                    <div class="d-flex gap-2">
                        <a href="<?php echo htmlspecialchars($meusUrl); ?>" class="btn btn-sm btn-outline-primary">
                            <i class="ti ti-calendar-user me-1"></i><?php echo __('Meus Agendamentos', 'agendamento'); ?>
                        </a>
                        <a href="<?php echo htmlspecialchars($agendaUrl); ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="ti ti-calendar me-1"></i><?php echo __('Agenda Geral', 'agendamento'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <?php if (empty($agendamentos)) { ?>
                <div class="card-body text-center text-muted" style="padding: 2.5rem 1rem;">
                    <i class="ti ti-calendar-off" style="font-size: 2.5rem; opacity: 0.4;"></i>
                    <p class="mb-0 mt-3" style="font-size: 0.95rem;"><?php echo __('Nenhum agendamento nos próximos 7 dias.', 'agendamento'); ?></p>
                </div>
            <?php } else { ?>
                <div class="card-body" style="padding: 0.75rem 1.25rem;">
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($agendamentos as $ag) {
                            $ticketId = (int) ($ag['tickets_id'] ?? 0);
                            $ticketName = $ag['ticket_name'] ?? '';
                            $status = self::normalizeStatus((string) ($ag['status'] ?? ''));
                            $startAt = strtotime((string) ($ag['data_hora_inicio'] ?? ''));
                            $endAt = strtotime((string) ($ag['data_hora_fim'] ?? ''));
                            $endereco = trim((string) ($ag['endereco_cliente'] ?? ''));
                            $contato = trim((string) ($ag['contato_cliente'] ?? ''));

                            $dateStr = $startAt !== false ? date('Y-m-d', $startAt) : '';
                            $isToday = ($dateStr === $todayStr);
                            $isTomorrow = ($dateStr === $tomorrowStr);

                            if ($isToday) {
                                $dayBadge = '<span class="badge bg-danger-lt" style="font-size: 0.7rem;">Hoje</span>';
                                $borderColor = '#e53e3e';
                                $bgColor = 'rgba(229,62,62,0.03)';
                            } elseif ($isTomorrow) {
                                $dayBadge = '<span class="badge bg-warning-lt" style="font-size: 0.7rem;">Amanhã</span>';
                                $borderColor = '#dd6b20';
                                $bgColor = 'rgba(221,107,32,0.03)';
                            } else {
                                $dayBadge = '';
                                $borderColor = '#e2e8f0';
                                $bgColor = '#fff';
                            }

                            $statusBadge = match ($status) {
                                self::STATUS_CONFIRMADO => '<span class="badge bg-warning-lt"><i class="ti ti-check me-1"></i>' . __('Confirmado', 'agendamento') . '</span>',
                                default => '<span class="badge bg-info-lt"><i class="ti ti-clock me-1"></i>' . __('Agendado', 'agendamento') . '</span>',
                            };

                            $timeStr = $startAt !== false ? date('H:i', $startAt) : '';
                            $endTimeStr = ($endAt !== false && $endAt > $startAt) ? ' - ' . date('H:i', $endAt) : '';
                            $dateDisplay = $startAt !== false ? date('d/m', $startAt) : '';
                        ?>
                        <div class="rounded-3" style="border-left: 3px solid <?php echo $borderColor; ?>; background: <?php echo $bgColor; ?>; padding: 0.75rem 1rem;">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div class="flex-grow-1" style="min-width: 0;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <a href="<?php echo htmlspecialchars($rootDoc . '/front/ticket.form.php?id=' . $ticketId); ?>" class="text-decoration-none fw-semibold" style="font-size: 0.9rem;">
                                            <i class="ti ti-ticket me-1"></i>#<?php echo $ticketId; ?>
                                        </a>
                                        <?php if ($ticketName !== '') { ?>
                                            <span class="text-muted text-truncate" style="font-size: 0.85rem;"><?php echo htmlspecialchars(mb_strimwidth($ticketName, 0, 50, '...')); ?></span>
                                        <?php } ?>
                                    </div>
                                    <div class="d-flex align-items-center flex-wrap gap-2" style="font-size: 0.8rem;">
                                        <?php if ($dayBadge !== '') { echo $dayBadge; } ?>
                                        <span class="text-muted">
                                            <i class="ti ti-calendar-event me-1"></i><?php echo $dateDisplay; ?>
                                        </span>
                                        <span class="fw-medium">
                                            <i class="ti ti-clock me-1 text-muted"></i><?php echo $timeStr . $endTimeStr; ?>
                                        </span>
                                        <?php echo $statusBadge; ?>
                                        <?php if ($endereco !== '') { ?>
                                            <span class="text-muted" title="<?php echo htmlspecialchars($endereco); ?>">
                                                <i class="ti ti-map-pin me-1"></i><?php echo htmlspecialchars(mb_strimwidth($endereco, 0, 35, '...')); ?>
                                            </span>
                                        <?php } elseif ($contato !== '') { ?>
                                            <span class="text-muted">
                                                <i class="ti ti-phone me-1"></i><?php echo htmlspecialchars($contato); ?>
                                            </span>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
                <?php if ($countAll > count($agendamentos)) { ?>
                    <div class="card-footer text-center border-top" style="padding: 0.75rem;">
                        <a href="<?php echo htmlspecialchars($meusUrl); ?>" class="text-muted text-decoration-none" style="font-size: 0.85rem;">
                            <?php echo sprintf(__('Ver todos os %d agendamentos pendentes', 'agendamento'), $countAll); ?>
                            <i class="ti ti-arrow-right ms-1"></i>
                        </a>
                    </div>
                <?php } ?>
            <?php } ?>
        </div>
        </td></tr>
        <?php
    }
}