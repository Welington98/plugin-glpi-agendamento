(function () {
    const bindTicketAgendamentoModal = () => {
        const modalElement = document.getElementById('plugin-agendamento-ticket-modal');
        if (!modalElement || typeof bootstrap === 'undefined') {
            return;
        }
        if (modalElement.dataset.agendamentoTicketBound === '1') {
            return;
        }
        modalElement.dataset.agendamentoTicketBound = '1';

        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const triggerSelector = '[data-open-modal="plugin-agendamento-ticket-modal"]';
        const technicianInput = document.querySelector("select[name='agendamento_users_id_tech']");
        const durationInput = document.getElementById('plugin-agendamento-ticket-duration');
        const dateInput = document.getElementById('plugin-agendamento-ticket-date');
        const startInput = document.getElementById('plugin-agendamento-ticket-start');
        const endInput = document.getElementById('plugin-agendamento-ticket-end');
        const contactInput = document.getElementById('plugin-agendamento-ticket-contact');
        const addressInput = document.getElementById('plugin-agendamento-ticket-address');
        const findSlotsButton = document.getElementById('plugin-agendamento-find-slots');
        const resultsContainer = document.getElementById('plugin-agendamento-slot-results');

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const renderMessage = (message, type = 'info') => {
            resultsContainer.hidden = false;
            resultsContainer.innerHTML = `<div class="alert alert-${type} mb-0">${escapeHtml(message)}</div>`;
        };

        const renderSlots = (slots) => {
            if (!Array.isArray(slots) || slots.length === 0) {
                renderMessage('Nenhum horário livre encontrado para os critérios informados.', 'warning');
                return;
            }

            const buttons = slots.map((slot) => (
                `<button type="button" class="btn btn-outline-primary plugin-agendamento-slot-option" data-start="${escapeHtml(slot.start)}" data-end="${escapeHtml(slot.end)}">` +
                `<i class="ti ti-clock me-1"></i>${escapeHtml(slot.label)}` +
                `</button>`
            )).join('');

            resultsContainer.hidden = false;
            resultsContainer.innerHTML = `
                <div class="border rounded p-3 bg-light">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <strong>Horários disponíveis</strong>
                        <span class="text-muted small">Clique para usar</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2">${buttons}</div>
                </div>
            `;
        };

        document.querySelectorAll(triggerSelector).forEach((button) => {
            if (button.dataset.agendamentoTicketTriggerBound === '1') {
                return;
            }
            button.dataset.agendamentoTicketTriggerBound = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                modal.show();
            });
        });

        modalElement.addEventListener('click', (event) => {
            const target = event.target.closest('.plugin-agendamento-slot-option');
            if (!target) {
                return;
            }
            if (startInput) {
                startInput.value = target.dataset.start || '';
            }
            if (endInput) {
                endInput.value = target.dataset.end || '';
            }
        });

        modalElement.addEventListener('shown.bs.modal', async () => {
            const ticketIdInput = document.querySelector("input[name='agendamento_tickets_id']");
            const ticketId = ticketIdInput ? ticketIdInput.value : '';
            if (!ticketId || !findSlotsButton) {
                return;
            }

            if ((!contactInput || contactInput.value.trim() === '') || (!addressInput || addressInput.value.trim() === '')) {
                try {
                    const url = `${findSlotsButton.dataset.actionsUrl}?action=ticket_metadata&ticket_id=${encodeURIComponent(ticketId)}`;
                    const response = await fetch(url, {
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    if (response.ok) {
                        const data = await response.json();
                        if (contactInput && contactInput.value.trim() === '') {
                            contactInput.value = data.contact || '';
                        }
                        if (addressInput && addressInput.value.trim() === '') {
                            addressInput.value = data.address || '';
                        }
                    }
                } catch (error) {
                    console.error('Erro ao carregar metadados do ticket', error);
                }
            }
        });

        if (findSlotsButton) {
            findSlotsButton.addEventListener('click', async () => {
                const technicianId = technicianInput ? technicianInput.value : '';
                const date = dateInput ? dateInput.value : '';
                const duration = durationInput ? durationInput.value : '60';

                if (!technicianId || technicianId === '0') {
                    renderMessage('Selecione um técnico antes de buscar horários.', 'warning');
                    return;
                }

                if (!date) {
                    renderMessage('Selecione uma data para buscar disponibilidade.', 'warning');
                    return;
                }

                renderMessage('Buscando horários disponíveis...', 'info');

                try {
                    const url = `${findSlotsButton.dataset.actionsUrl}?action=available_slots&tech_id=${encodeURIComponent(technicianId)}&date=${encodeURIComponent(date)}&duration=${encodeURIComponent(duration)}`;
                    const response = await fetch(url, {
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await response.json();
                    if (!response.ok || data.success === false) {
                        throw new Error(data.message || 'Não foi possível carregar horários disponíveis.');
                    }
                    renderSlots(data.slots || []);
                } catch (error) {
                    renderMessage(error.message || 'Erro ao buscar disponibilidade.', 'danger');
                }
            });
        }
    };

    const scheduleBindTicketAgendamentoModal = () => {
        bindTicketAgendamentoModal();
        window.setTimeout(bindTicketAgendamentoModal, 0);
        window.setTimeout(bindTicketAgendamentoModal, 250);
    };

    window.pluginAgendamentoBindTicketModal = scheduleBindTicketAgendamentoModal;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleBindTicketAgendamentoModal);
    } else {
        scheduleBindTicketAgendamentoModal();
    }

    window.addEventListener('load', scheduleBindTicketAgendamentoModal);
    document.addEventListener('glpi:page_loaded', scheduleBindTicketAgendamentoModal);
})();
