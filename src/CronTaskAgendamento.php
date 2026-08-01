<?php

namespace GlpiPlugin\Agendamento;

use CronTask;

class CronTaskAgendamento
{
    public const TASK_NAME = 'AgendamentoLembretes';

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            self::TASK_NAME => ['description' => __('Envia lembretes de agendamentos futuros', 'agendamento')],
            default => [],
        };
    }

    public static function cronAgendamentoLembretes(CronTask $task): int
    {
        $leadHours = (int) Config::getConfigValue('reminder_hours_before', 24);
        if ($leadHours <= 0) {
            $leadHours = 24;
        }

        $windowStart = date('Y-m-d H:i:s');
        $windowEnd = date('Y-m-d H:i:s', strtotime('+' . $leadHours . ' hours'));

        $sent = 0;
        foreach (Agendamento::getPendingReminders($windowStart, $windowEnd) as $agendamento) {
            $agendamentoId = (int) ($agendamento['id'] ?? 0);
            if ($agendamentoId <= 0) {
                continue;
            }

            Agendamento::sendReminder($agendamentoId);
            $sent++;
        }

        $task->addVolume($sent);

        return $sent > 0 ? 1 : 0;
    }
}
