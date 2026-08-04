<?php

namespace GlpiPlugin\Agendamento;

use CommonITILActor;
use Dropdown;
use Notification;
use NotificationTarget;
use Ticket as GlpiTicket;
use User;

class NotificationTargetAgendamentoItem extends NotificationTarget
{
    // Custom target id, deliberately outside the range of GLPI core's
    // Notification::* target constants (1, 3, 5, 6, 11, 23, ...) so it falls
    // through to addSpecificTargets() in NotificationTarget::addForTarget().
    private const TARGET_TICKET_REQUESTER = 900001;

    public function getEvents()
    {
        return [
            'new'      => __('Agendamento criado', 'agendamento'),
            'update'   => __('Agendamento atualizado/reagendado', 'agendamento'),
            'cancel'   => __('Agendamento cancelado', 'agendamento'),
            'reminder' => __('Lembrete de agendamento', 'agendamento'),
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(Notification::ITEM_TECH_IN_CHARGE, __('Técnico responsável', 'agendamento'));
        $this->addTarget(self::TARGET_TICKET_REQUESTER, __('Solicitante do chamado', 'agendamento'));
    }

    public function addSpecificTargets($data, $options)
    {
        if ((int) ($data['items_id'] ?? 0) !== self::TARGET_TICKET_REQUESTER) {
            return;
        }

        if (!($this->obj instanceof AgendamentoItem)) {
            return;
        }

        $ticketId = (int) ($this->obj->fields['tickets_id'] ?? 0);
        if ($ticketId <= 0) {
            return;
        }

        $ticket = new GlpiTicket();
        if (!$ticket->getFromDB($ticketId)) {
            return;
        }

        foreach ($ticket->getUsers(CommonITILActor::REQUESTER) as $requester) {
            $userId = (int) ($requester['users_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $user = new User();
            if ($user->getFromDB($userId)) {
                $this->addToRecipientsList([
                    'language' => $user->fields['language'],
                    'users_id' => $user->getID(),
                ]);
            }
        }
    }

    public function addDataForTemplate($event, $options = [])
    {
        $agendamento = $options['agendamento'] ?? [];
        $ticketId = (int) ($agendamento['tickets_id'] ?? 0);
        $ticketExtras = self::getTicketExtras($ticketId);

        $this->data['##agendamento.ticket_id##'] = $ticketId;
        $this->data['##agendamento.technician##'] = trim((string) ($agendamento['tecnico_nome'] ?? ''));
        $this->data['##agendamento.date_start##'] = self::formatDate((string) ($agendamento['data_hora_inicio'] ?? ''));
        $this->data['##agendamento.date_end##'] = self::formatDate((string) ($agendamento['data_hora_fim'] ?? ''));
        $this->data['##agendamento.status##'] = Agendamento::getStatusLabel((string) ($agendamento['status'] ?? ''));
        $this->data['##agendamento.cancel_reason##'] = trim((string) ($options['cancel_reason'] ?? ''));
        $this->data['##agendamento.reschedule_reason##'] = trim((string) ($options['reschedule_reason'] ?? ''));
        $this->data['##agendamento.url##'] = self::buildTicketUrl($ticketId);
        $this->data['##agendamento.ticket_title##'] = $ticketExtras['title'];
        $this->data['##agendamento.ticket_description##'] = $ticketExtras['description'];
        $this->data['##agendamento.requester##'] = $ticketExtras['requester'];
        $this->data['##agendamento.entity##'] = $ticketExtras['entity'];
    }

    public function getTags()
    {
        $tags = [
            'agendamento.ticket_id'          => __('Número do chamado', 'agendamento'),
            'agendamento.ticket_title'       => __('Título do chamado', 'agendamento'),
            'agendamento.ticket_description' => __('Descrição do chamado', 'agendamento'),
            'agendamento.requester'          => __('Solicitante', 'agendamento'),
            'agendamento.entity'             => __('Entidade', 'agendamento'),
            'agendamento.technician'         => __('Técnico', 'agendamento'),
            'agendamento.date_start'         => __('Início do agendamento', 'agendamento'),
            'agendamento.date_end'           => __('Fim do agendamento', 'agendamento'),
            'agendamento.status'             => __('Status', 'agendamento'),
            'agendamento.cancel_reason'      => __('Motivo do cancelamento', 'agendamento'),
            'agendamento.reschedule_reason'  => __('Motivo do reagendamento', 'agendamento'),
            'agendamento.url'                => __('Link do chamado', 'agendamento'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag'   => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        asort($this->tag_descriptions);
    }

    private static function getTicketExtras(int $ticketId): array
    {
        $extras = [
            'title' => '',
            'description' => '',
            'requester' => '',
            'entity' => '',
        ];

        if ($ticketId <= 0) {
            return $extras;
        }

        $ticket = new GlpiTicket();
        if (!$ticket->getFromDB($ticketId)) {
            return $extras;
        }

        $extras['title'] = trim((string) ($ticket->fields['name'] ?? ''));
        $extras['description'] = trim(strip_tags(html_entity_decode((string) ($ticket->fields['content'] ?? ''), ENT_QUOTES)));
        $extras['entity'] = Dropdown::getDropdownName('glpi_entities', (int) ($ticket->fields['entities_id'] ?? 0));

        $requesterNames = [];
        foreach ($ticket->getUsers(CommonITILActor::REQUESTER) as $requester) {
            $userId = (int) ($requester['users_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $user = new User();
            if ($user->getFromDB($userId)) {
                $requesterNames[] = $user->getFriendlyName();
            }
        }
        $extras['requester'] = implode(', ', array_unique(array_filter($requesterNames)));

        return $extras;
    }

    private static function formatDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? '' : date('d/m/Y H:i', $timestamp);
    }

    private static function buildTicketUrl(int $ticketId): string
    {
        global $CFG_GLPI;

        if ($ticketId <= 0) {
            return '';
        }

        $rootDoc = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        return $rootDoc . '/front/ticket.form.php?id=' . $ticketId . '&forcetab=GlpiPlugin\Agendamento\TicketAgendamento$1';
    }
}
