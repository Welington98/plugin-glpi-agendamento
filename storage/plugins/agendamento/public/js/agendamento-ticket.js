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
        const calendarShell = document.getElementById('plugin-agendamento-ticket-calendar-shell');
        const calendarElement = document.getElementById('plugin-agendamento-ticket-calendar');
        const selectionHint = document.getElementById('plugin-agendamento-ticket-selection-hint');
        const selectionBadge = document.getElementById('plugin-agendamento-ticket-selection-badge');
        const modalForm = modalElement.querySelector('form');
        const modalCsrfInput = modalElement.querySelector("input[name='_glpi_csrf_token']");

        let ticketCalendar = null;

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const getCalendarConfig = () => {
            if (!calendarElement) {
                return {};
            }

            try {
                return JSON.parse(calendarElement.dataset.config || '{}');
            } catch (_error) {
                return {};
            }
        };

        const syncModalCsrfToken = () => {
            if (!modalCsrfInput) {
                return;
            }

            const pageToken = Array.from(document.querySelectorAll("input[name='_glpi_csrf_token']"))
                .map((input) => input.value)
                .find((value) => value && value !== modalCsrfInput.value);

            if (pageToken) {
                modalCsrfInput.value = pageToken;
            }
        };

        const formatDateForInput = (value) => {
            const date = value instanceof Date ? value : new Date(value);
            return Number.isNaN(date.getTime()) ? '' : date.toISOString().slice(0, 10);
        };

        const formatDateTimeLocal = (value) => {
            const date = value instanceof Date ? value : new Date(value);
            if (Number.isNaN(date.getTime())) {
                return '';
            }

            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            return `${year}-${month}-${day}T${hours}:${minutes}`;
        };

        const formatSelectionLabel = (start, end) => {
            const formatter = new Intl.DateTimeFormat('pt-BR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
            const timeFormatter = new Intl.DateTimeFormat('pt-BR', {
                hour: '2-digit',
                minute: '2-digit',
            });
            return `${formatter.format(start)} - ${timeFormatter.format(end)}`;
        };

        const getDurationMinutes = () => {
            const duration = Number.parseInt(durationInput ? durationInput.value : '60', 10);
            if (Number.isNaN(duration)) {
                return 60;
            }
            return Math.min(Math.max(duration, 15), 480);
        };

        const updateSelectedPeriod = (start, end) => {
            if (startInput) {
                startInput.value = formatDateTimeLocal(start);
            }
            if (endInput) {
                endInput.value = formatDateTimeLocal(end);
            }
            if (dateInput) {
                dateInput.value = formatDateForInput(start);
            }
            if (selectionBadge) {
                selectionBadge.hidden = false;
                selectionBadge.textContent = formatSelectionLabel(start, end);
            }
            if (selectionHint) {
                selectionHint.textContent = 'Horario selecionado na agenda visual.';
            }
        };

        const renderMessage = (message, type = 'info') => {
            resultsContainer.hidden = false;
            resultsContainer.innerHTML = `<div class="alert alert-${type} mb-0">${escapeHtml(message)}</div>`;
        };

        const clearMessage = () => {
            resultsContainer.hidden = true;
            resultsContainer.innerHTML = '';
        };

        const getCalendarLocale = () => {
            const localeKeys = typeof FullCalendarLocales === 'object' && FullCalendarLocales !== null ? Object.keys(FullCalendarLocales) : [];
            if (localeKeys.includes('pt-br')) {
                return 'pt-br';
            }
            if (localeKeys.includes('ptBr')) {
                return 'ptBr';
            }
            return undefined;
        };

        const ensureCalendar = () => {
            if (!calendarElement || typeof FullCalendar === 'undefined') {
                return null;
            }

            if (ticketCalendar) {
                return ticketCalendar;
            }

            const config = getCalendarConfig();
            const locale = getCalendarLocale();

            ticketCalendar = new FullCalendar.Calendar(calendarElement, {
                plugins: ['dayGrid', 'timeGrid', 'interaction'],
                locale,
                defaultView: 'timeGridDay',
                defaultDate: config.initialDate,
                firstDay: 1,
                nowIndicator: true,
                editable: false,
                selectable: true,
                selectMirror: true,
                selectOverlap: false,
                allDaySlot: false,
                height: config.calendarHeight || 520,
                slotDuration: config.slotDuration || '00:30:00',
                slotLabelInterval: '01:00:00',
                minTime: config.slotMinTime || '07:00:00',
                maxTime: config.slotMaxTime || '21:00:00',
                scrollTime: '08:00:00',
                header: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'timeGridDay,timeGridWeek',
                },
                buttonText: {
                    today: config.texts?.today || 'Hoje',
                    week: config.texts?.week || 'Semana',
                    day: config.texts?.day || 'Dia',
                },
                eventTimeFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                },
                events: async (info, successCallback, failureCallback) => {
                    const technicianId = technicianInput ? technicianInput.value : '';
                    if (!technicianId || technicianId === '0') {
                        successCallback([]);
                        return;
                    }

                    try {
                        const configData = getCalendarConfig();
                        const url = `${configData.actionsUrl}?action=events&tech_id=${encodeURIComponent(technicianId)}&start=${encodeURIComponent(info.startStr)}&end=${encodeURIComponent(info.endStr)}`;
                        const response = await fetch(url, {
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const data = await response.json();
                        if (!response.ok || !Array.isArray(data)) {
                            throw new Error(configData.texts?.loadError || 'Nao foi possivel carregar a agenda visual.');
                        }
                        successCallback(data);
                    } catch (error) {
                        failureCallback(error);
                    }
                },
                dateClick: (info) => {
                    const start = new Date(info.date);
                    const end = new Date(start.getTime() + (getDurationMinutes() * 60 * 1000));
                    updateSelectedPeriod(start, end);
                    clearMessage();
                },
                select: (info) => {
                    const start = new Date(info.start);
                    const selectedEnd = info.end ? new Date(info.end) : null;
                    const fallbackEnd = new Date(start.getTime() + (getDurationMinutes() * 60 * 1000));
                    const end = selectedEnd && selectedEnd > start ? selectedEnd : fallbackEnd;
                    updateSelectedPeriod(start, end);
                    clearMessage();
                    ticketCalendar.unselect();
                },
                eventClick: () => {
                    const configData = getCalendarConfig();
                    renderMessage(configData.texts?.busyWarning || 'Este horário já está ocupado.', 'warning');
                },
                eventRender: (info) => {
                    const titleEl = info.el.querySelector('.fc-title');
                    if (titleEl) {
                        titleEl.innerHTML = `<strong>${escapeHtml(info.event.title || '')}</strong>`;
                    }
                },
                datesRender: () => {
                    const currentDate = ticketCalendar.getDate();
                    if (dateInput && currentDate instanceof Date && !Number.isNaN(currentDate.getTime())) {
                        dateInput.value = formatDateForInput(currentDate);
                    }
                },
            });

            ticketCalendar.render();
            return ticketCalendar;
        };

        const loadVisualCalendar = async () => {
            const technicianId = technicianInput ? technicianInput.value : '';
            const date = dateInput ? dateInput.value : '';
            const config = getCalendarConfig();

            if (!technicianId || technicianId === '0') {
                renderMessage(config.texts?.missingTech || 'Selecione um técnico antes de abrir a agenda.', 'warning');
                if (calendarShell) {
                    calendarShell.hidden = true;
                }
                return;
            }

            if (!date) {
                renderMessage(config.texts?.missingDate || 'Selecione uma data para abrir a agenda.', 'warning');
                if (calendarShell) {
                    calendarShell.hidden = true;
                }
                return;
            }

            const calendar = ensureCalendar();
            if (!calendar) {
                renderMessage(config.texts?.loadError || 'Nao foi possivel carregar a agenda visual.', 'danger');
                return;
            }

            if (calendarShell) {
                calendarShell.hidden = false;
            }

            renderMessage(config.texts?.selectionHint || 'Clique em um horário livre na agenda para preencher o agendamento.', 'info');
            calendar.gotoDate(date);
            calendar.refetchEvents();
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
            syncModalCsrfToken();

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

            if (technicianInput && technicianInput.value && technicianInput.value !== '0' && dateInput && dateInput.value) {
                loadVisualCalendar();
            }
        });

        if (findSlotsButton) {
            findSlotsButton.addEventListener('click', async () => {
                loadVisualCalendar();
            });
        }

        if (modalForm) {
            modalForm.addEventListener('submit', () => {
                syncModalCsrfToken();
            });
        }

        if (technicianInput) {
            technicianInput.addEventListener('change', () => {
                if (selectionBadge) {
                    selectionBadge.hidden = true;
                    selectionBadge.textContent = '';
                }
                if (startInput) {
                    startInput.value = '';
                }
                if (endInput) {
                    endInput.value = '';
                }
                if (dateInput && dateInput.value) {
                    loadVisualCalendar();
                }
            });
        }

        if (dateInput) {
            dateInput.addEventListener('change', () => {
                if (ticketCalendar && dateInput.value) {
                    ticketCalendar.gotoDate(dateInput.value);
                    ticketCalendar.refetchEvents();
                }
            });
        }

        if (durationInput) {
            durationInput.addEventListener('change', () => {
                if (selectionBadge && !selectionBadge.hidden && startInput && startInput.value) {
                    const start = new Date(startInput.value);
                    if (!Number.isNaN(start.getTime())) {
                        const end = new Date(start.getTime() + (getDurationMinutes() * 60 * 1000));
                        updateSelectedPeriod(start, end);
                    }
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
