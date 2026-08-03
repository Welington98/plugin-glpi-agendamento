<?php

namespace GlpiPlugin\Agendamento;

class Dashboard
{
    private const TABLE = 'glpi_plugin_agendamento_agendamentos';

    public static function getCards(array $cards = []): array
    {
        $cards['plugin_agendamento_kpi_upcoming_week'] = [
            'widgettype' => ['bigNumber'],
            'label' => __('Agendamentos - Próximos 7 dias', 'agendamento'),
            'group' => __('Agendamento', 'agendamento'),
            'provider' => 'GlpiPlugin\\Agendamento\\Dashboard::cardKpiUpcomingWeek',
        ];

        $cards['plugin_agendamento_kpi_realizados_mes'] = [
            'widgettype' => ['bigNumber'],
            'label' => __('Agendamentos realizados no mês', 'agendamento'),
            'group' => __('Agendamento', 'agendamento'),
            'provider' => 'GlpiPlugin\\Agendamento\\Dashboard::cardKpiRealizadosMes',
        ];

        $cards['plugin_agendamento_kpi_cancelados_mes'] = [
            'widgettype' => ['bigNumber'],
            'label' => __('Agendamentos cancelados no mês', 'agendamento'),
            'group' => __('Agendamento', 'agendamento'),
            'provider' => 'GlpiPlugin\\Agendamento\\Dashboard::cardKpiCanceladosMes',
        ];

        return $cards;
    }

    public static function cardKpiUpcomingWeek(array $params = []): array
    {
        $todayStart = date('Y-m-d 00:00:00');
        $weekEnd = date('Y-m-d 23:59:59', strtotime('+6 days'));

        $count = self::countAgendamentos([
            'status' => [Agendamento::STATUS_AGENDADO, Agendamento::STATUS_CONFIRMADO],
            'data_hora_inicio' => ['>=', $todayStart],
            [self::TABLE . '.data_hora_inicio' => ['<=', $weekEnd]],
        ]);

        return [
            'number' => $count,
            'url' => self::buildAgendaUrl(),
            'label' => __('Agendamentos - Próximos 7 dias', 'agendamento'),
            'icon' => 'ti ti-calendar-event',
        ];
    }

    public static function cardKpiRealizadosMes(array $params = []): array
    {
        [$start, $end] = self::currentMonthRange();

        $count = self::countAgendamentos([
            'status' => Agendamento::STATUS_REALIZADO,
            'data_hora_inicio' => ['>=', $start],
            [self::TABLE . '.data_hora_inicio' => ['<=', $end]],
        ]);

        return [
            'number' => $count,
            'url' => self::buildAgendaUrl(['status' => Agendamento::STATUS_REALIZADO]),
            'label' => __('Agendamentos realizados no mês', 'agendamento'),
            'icon' => 'ti ti-checkbox',
        ];
    }

    public static function cardKpiCanceladosMes(array $params = []): array
    {
        [$start, $end] = self::currentMonthRange();

        $count = self::countAgendamentos([
            'status' => Agendamento::STATUS_CANCELADO,
            'data_hora_inicio' => ['>=', $start],
            [self::TABLE . '.data_hora_inicio' => ['<=', $end]],
        ]);

        return [
            'number' => $count,
            'url' => self::buildAgendaUrl(['status' => Agendamento::STATUS_CANCELADO]),
            'label' => __('Agendamentos cancelados no mês', 'agendamento'),
            'icon' => 'ti ti-calendar-cancel',
        ];
    }

    private static function countAgendamentos(array $where): int
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return 0;
        }

        $iterator = $DB->request([
            'SELECT' => ['COUNT' => 'id AS total'],
            'FROM' => self::TABLE,
            'WHERE' => $where,
        ]);

        foreach ($iterator as $row) {
            return (int) ($row['total'] ?? 0);
        }

        return 0;
    }

    private static function currentMonthRange(): array
    {
        $start = date('Y-m-01 00:00:00');
        $end = date('Y-m-t 23:59:59');

        return [$start, $end];
    }

    private static function buildAgendaUrl(array $query = []): string
    {
        global $CFG_GLPI;

        $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        $url = $rootDoc . '/plugins/agendamento/front/agendamento.php';

        return $query !== [] ? $url . '?' . http_build_query($query) : $url;
    }
}
