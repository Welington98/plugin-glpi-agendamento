<?php

namespace GlpiPlugin\Agendamento;

use Toolbox;

class GoogleCalendarSync
{
    private const CALENDAR_API_BASE = 'https://www.googleapis.com/calendar/v3';
    private const MAX_RETRIES = 3;

    private const STATUS_COLOR_MAP = [
        'agendado' => '9',
        'confirmado' => '2',
        'cancelado' => '11',
        'realizado' => '8',
    ];

    public static function createEvent(array $agendamento, int $techUserId): ?string
    {
        if (!self::isSyncEnabled()) {
            return null;
        }

        $token = GoogleCalendarAuth::getValidToken($techUserId);
        if ($token === null) {
            return null;
        }

        $calendarId = self::getCalendarId($techUserId);
        $payload = self::buildEventPayload($agendamento);
        $url = self::CALENDAR_API_BASE . '/calendars/' . urlencode($calendarId) . '/events';

        $response = self::executeWithRetry('POST', $url, $payload, $token, $techUserId);

        if (isset($response['id'])) {
            Toolbox::logInFile('agendamento', "Google Calendar: evento criado ({$response['id']}) para agendamento #{$agendamento['id']}");
            return $response['id'];
        }

        self::logApiError('createEvent', $agendamento['id'] ?? 0, $response);
        return null;
    }

    public static function updateEvent(array $agendamento, int $techUserId, string $googleEventId): bool
    {
        if (!self::isSyncEnabled() || $googleEventId === '') {
            return false;
        }

        $token = GoogleCalendarAuth::getValidToken($techUserId);
        if ($token === null) {
            return false;
        }

        $calendarId = self::getCalendarId($techUserId);
        $payload = self::buildEventPayload($agendamento);
        $url = self::CALENDAR_API_BASE . '/calendars/' . urlencode($calendarId) . '/events/' . urlencode($googleEventId);

        $response = self::executeWithRetry('PUT', $url, $payload, $token, $techUserId);

        if (isset($response['id'])) {
            Toolbox::logInFile('agendamento', "Google Calendar: evento atualizado ({$googleEventId}) para agendamento #{$agendamento['id']}");
            return true;
        }

        self::logApiError('updateEvent', $agendamento['id'] ?? 0, $response);
        return false;
    }

    public static function deleteEvent(int $techUserId, string $googleEventId): bool
    {
        if (!self::isSyncEnabled() || $googleEventId === '') {
            return false;
        }

        $token = GoogleCalendarAuth::getValidToken($techUserId);
        if ($token === null) {
            return false;
        }

        $calendarId = self::getCalendarId($techUserId);
        $url = self::CALENDAR_API_BASE . '/calendars/' . urlencode($calendarId) . '/events/' . urlencode($googleEventId);

        $response = self::executeWithRetry('DELETE', $url, null, $token, $techUserId);

        $httpCode = $response['http_code'] ?? 0;
        if ($httpCode >= 200 && $httpCode < 300) {
            Toolbox::logInFile('agendamento', "Google Calendar: evento removido ({$googleEventId})");
            return true;
        }

        if ($httpCode === 404 || $httpCode === 410) {
            Toolbox::logInFile('agendamento', "Google Calendar: evento já removido ({$googleEventId})");
            return true;
        }

        self::logApiError('deleteEvent', 0, $response);
        return false;
    }

    public static function syncAgendamento(array $agendamento, int $techUserId): ?string
    {
        $googleEventId = $agendamento['google_event_id'] ?? null;

        if (!empty($googleEventId)) {
            self::updateEvent($agendamento, $techUserId, $googleEventId);
            return $googleEventId;
        }

        return self::createEvent($agendamento, $techUserId);
    }

    public static function syncAllForTechnician(int $techUserId): array
    {
        global $DB;

        if (!self::isSyncEnabled() || !GoogleCalendarAuth::isConnected($techUserId)) {
            return ['synced' => 0, 'errors' => 0];
        }

        $iterator = $DB->request([
            'FROM' => 'glpi_plugin_agendamento_agendamentos',
            'WHERE' => [
                'users_id_tech' => $techUserId,
                'status' => ['agendado', 'confirmado'],
            ],
        ]);

        $synced = 0;
        $errors = 0;

        foreach ($iterator as $agendamento) {
            $eventId = self::syncAgendamento($agendamento, $techUserId);
            if ($eventId !== null) {
                if (empty($agendamento['google_event_id'])) {
                    $DB->update('glpi_plugin_agendamento_agendamentos', [
                        'google_event_id' => $eventId,
                    ], [
                        'id' => $agendamento['id'],
                    ]);
                }
                $synced++;
            } else {
                $errors++;
            }
        }

        Toolbox::logInFile('agendamento', "Google Calendar: sync completo para técnico #{$techUserId} - {$synced} sincronizados, {$errors} erros");
        return ['synced' => $synced, 'errors' => $errors];
    }

    public static function buildEventPayload(array $agendamento): array
    {
        global $CFG_GLPI;

        $ticketId = (int) ($agendamento['tickets_id'] ?? 0);
        $ticketName = $agendamento['ticket_name'] ?? '';

        if ($ticketName === '' && $ticketId > 0) {
            $ticket = new \Ticket();
            if ($ticket->getFromDB($ticketId)) {
                $ticketName = $ticket->fields['name'] ?? '';
            }
        }

        $summary = "[GLPI #{$ticketId}] Atendimento";
        if ($ticketName !== '') {
            $summary .= " - {$ticketName}";
        }

        $techName = $agendamento['tecnico_nome'] ?? '';
        $contato = $agendamento['contato_cliente'] ?? '';
        $endereco = $agendamento['endereco_cliente'] ?? '';
        $status = $agendamento['status'] ?? 'agendado';
        $observacoes = $agendamento['observacoes'] ?? '';
        $baseUrl = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');

        $description = "📋 Chamado GLPI #{$ticketId}\n";
        if ($ticketName !== '') {
            $description .= "Título: {$ticketName}\n";
        }
        $description .= "\n👤 Técnico: " . ($techName !== '' ? $techName : 'Não atribuído') . "\n";
        if ($contato !== '') {
            $description .= "📞 Contato Cliente: {$contato}\n";
        }
        if ($endereco !== '') {
            $description .= "📍 Endereço: {$endereco}\n";
        }
        $description .= "\n📌 Status: " . ucfirst($status) . "\n";
        if ($observacoes !== '') {
            $description .= "📝 Observações: {$observacoes}\n";
        }
        if ($baseUrl !== '' && $ticketId > 0) {
            $description .= "\n🔗 Abrir no GLPI: {$baseUrl}/front/ticket.form.php?id={$ticketId}";
        }

        $start = $agendamento['data_hora_inicio'] ?? '';
        $end = $agendamento['data_hora_fim'] ?? $start;
        if ($end === '' || $end === $start) {
            $endTime = strtotime($start);
            if ($endTime !== false) {
                $end = date('Y-m-d\TH:i:s', $endTime + 3600);
            }
        }

        $timezone = date_default_timezone_get() ?: 'America/Sao_Paulo';

        $event = [
            'summary' => $summary,
            'description' => $description,
            'start' => [
                'dateTime' => self::toIso8601($start),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => self::toIso8601($end),
                'timeZone' => $timezone,
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'popup', 'minutes' => 60],
                    ['method' => 'popup', 'minutes' => 30],
                ],
            ],
        ];

        if ($endereco !== '') {
            $event['location'] = $endereco;
        }

        $colorId = self::STATUS_COLOR_MAP[$status] ?? '9';
        $event['colorId'] = $colorId;

        return $event;
    }

    private static function toIso8601(string $dateTime): string
    {
        $ts = strtotime($dateTime);
        if ($ts === false) {
            return $dateTime;
        }
        return date('Y-m-d\TH:i:s', $ts);
    }

    private static function getCalendarId(int $userId): string
    {
        $tokenData = GoogleCalendarAuth::getTokenData($userId);
        return $tokenData['calendar_id'] ?? 'primary';
    }

    private static function isSyncEnabled(): bool
    {
        $config = Config::getConfig();
        return (int) ($config['google_sync_enabled'] ?? 0) === 1
            && trim($config['google_client_id'] ?? '') !== '';
    }

    private static function executeWithRetry(string $method, string $url, ?array $body, string $token, int $userId): array
    {
        $attempts = 0;
        $lastResponse = [];

        while ($attempts < self::MAX_RETRIES) {
            $response = GoogleCalendarAuth::httpRequest($method, $url, $body, $token);
            $httpCode = $response['http_code'] ?? 0;

            if ($httpCode >= 200 && $httpCode < 300) {
                return $response;
            }

            if ($httpCode === 401) {
                $newToken = GoogleCalendarAuth::refreshAccessToken($userId);
                if ($newToken !== null) {
                    $token = $newToken;
                    $attempts++;
                    continue;
                }
                return $response;
            }

            if ($httpCode === 429 || $httpCode >= 500) {
                $attempts++;
                $delay = (int) pow(2, $attempts) * 100000;
                usleep($delay);
                $lastResponse = $response;
                continue;
            }

            return $response;
        }

        return $lastResponse;
    }

    private static function logApiError(string $operation, int $agendamentoId, array $response): void
    {
        $error = $response['error']['message'] ?? $response['error_description'] ?? json_encode($response);
        $httpCode = $response['http_code'] ?? 'N/A';
        Toolbox::logInFile(
            'agendamento',
            "Google Calendar API error [{$operation}] agendamento #{$agendamentoId} HTTP {$httpCode}: {$error}"
        );
    }
}
