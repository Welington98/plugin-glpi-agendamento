(function () {
    const bindModalControls = () => {
        // We use Bootstrap 5 Modals via jQuery which GLPI supports
        // Fix for Select2 inside Bootstrap Modal (Focus issue)
        $.fn.modal.Constructor.prototype._enforceFocus = function() {};

        document.querySelectorAll('[data-open-modal]').forEach((button) => {
            if (button.dataset.agendamentoBound === '1') {
                return;
            }
            button.dataset.agendamentoBound = '1';
            button.addEventListener('click', () => {
                const targetId = button.getAttribute('data-open-modal');
                const $targetModal = $('#' + targetId);
                
                if (targetId === 'plugin-agendamento-create-modal') {
                    // Reset form logic
                    const defaultStart = new Date();
                    defaultStart.setMinutes(0, 0, 0);
                    defaultStart.setHours(defaultStart.getHours() + 1);
                    const defaultEnd = new Date(defaultStart.getTime() + 60 * 60 * 1000);

                    // Form reset logic
                    $('#plugin-agendamento-form-action').val('create');
                    $('#plugin-agendamento-form-id').val('0');
                    $('#plugin-agendamento-form-title').text('Agendar chamado');
                    $('#plugin-agendamento-form-submit').html('<i class="ti ti-device-floppy me-1"></i> Salvar');
                    
                    // Reset Select2s
                    $("select[name='agendamento_tickets_id']").val(null).trigger('change');
                    $("select[name='agendamento_users_id_tech']").val('0').trigger('change');
                    
                    $('#agendamento_status').val('agendado');
                    $('#agendamento_observacoes').val('');
                    $('#agendamento_contato_cliente').val('');
                    $('#agendamento_endereco_cliente').val('');
                    
                    const startStr = new Date(defaultStart.getTime() - defaultStart.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                    const endStr = new Date(defaultEnd.getTime() - defaultEnd.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                    
                    $('#agendamento_data_hora_inicio').val(startStr);
                    $('#agendamento_data_hora_fim').val(endStr);
                }

                $targetModal.modal('show');
            });
        });

        document.querySelectorAll('[data-close-modal]').forEach((button) => {
            if (button.dataset.agendamentoBound === '1') {
                return;
            }
            button.dataset.agendamentoBound = '1';
            button.addEventListener('click', () => {
                const targetId = button.getAttribute('data-close-modal');
                $('#' + targetId).modal('hide');
            });
        });
    };

    const initializeCalendar = () => {
        const calendarEl = document.getElementById('plugin-agendamento-calendar');
        if (!calendarEl || typeof FullCalendar === 'undefined') {
            return;
        }
        if (calendarEl.dataset.agendamentoInitialized === '1') {
            return;
        }

        let config = {};
        try {
            config = JSON.parse(calendarEl.dataset.config || '{}');
        } catch (error) {
            return;
        }

        const createModal = document.getElementById('plugin-agendamento-create-modal');
        const detailsModal = document.getElementById('plugin-agendamento-details-modal');
        const filterTechnician = document.getElementById('dropdown_plugin_agendamento_filter_tech1200');
        const filterStatus = document.getElementById('plugin-agendamento-filter-status');
        const formActionInput = document.getElementById('plugin-agendamento-form-action');
        const formIdInput = document.getElementById('plugin-agendamento-form-id');
        const formTitle = document.getElementById('plugin-agendamento-form-title');
        const formSubmit = document.getElementById('plugin-agendamento-form-submit');
        const ticketInput = document.querySelector("select[name='agendamento_tickets_id']");
        const technicianInput = document.querySelector("select[name='agendamento_users_id_tech']");
        const statusInput = document.getElementById('agendamento_status');
        const notesInput = document.getElementById('agendamento_observacoes');
        const clientContactInput = document.getElementById('agendamento_contato_cliente');
        const clientAddressInput = document.getElementById('agendamento_endereco_cliente');
        const startInput = document.getElementById('agendamento_data_hora_inicio');
        const endInput = document.getElementById('agendamento_data_hora_fim');
        const detailTitle = document.getElementById('plugin-agendamento-detail-title');
        const detailStatus = document.getElementById('plugin-agendamento-detail-status');
        const detailTime = document.getElementById('plugin-agendamento-detail-time');
        const detailTech = document.getElementById('plugin-agendamento-detail-tech');
        const detailContact = document.getElementById('plugin-agendamento-detail-contact');
        const detailAddress = document.getElementById('plugin-agendamento-detail-address');
        const detailTask = document.getElementById('plugin-agendamento-detail-task');
        const detailNotes = document.getElementById('plugin-agendamento-detail-notes');
        const detailHistory = document.getElementById('plugin-agendamento-detail-history');
        const detailTicketId = document.getElementById('plugin-agendamento-detail-ticket-id');
        const detailAgendamentoId = document.getElementById('plugin-agendamento-detail-agendamento-id');
        const detailTicketLink = document.getElementById('plugin-agendamento-detail-ticket-link');
        const editButton = document.getElementById('plugin-agendamento-edit-button');
        const cancelToggleButton = document.getElementById('plugin-agendamento-cancel-toggle');
        const cancelPanel = document.getElementById('plugin-agendamento-cancel-panel');
        const cancelReasonInput = document.getElementById('plugin-agendamento-cancel-reason');
        const cancelBackButton = document.getElementById('plugin-agendamento-cancel-back');
        const detailActionsLeft = document.getElementById('plugin-agendamento-detail-actions-left');
        const detailActionsRight = document.getElementById('plugin-agendamento-detail-actions-right');
        const syncViewInputs = document.querySelectorAll('.plugin-agendamento-sync-view');
        const syncDateInputs = document.querySelectorAll('.plugin-agendamento-sync-date');
        const localeKeys = typeof FullCalendarLocales === 'object' && FullCalendarLocales !== null ? Object.keys(FullCalendarLocales) : [];
        const calendarViewMap = { month: 'dayGridMonth', week: 'timeGridWeek', day: 'timeGridDay' };
        const reverseViewMap = { dayGridMonth: 'month', timeGridWeek: 'week', timeGridDay: 'day' };
        let selectedEventData = null;

    const toInputValue = (date) => {
        const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 16);
    };

    const toQueryDate = (date) => {
        const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 10);
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const formatEventTime = (start, end) => {
        if (!(start instanceof Date)) {
            return '-';
        }

        const dateText = start.toLocaleDateString(config.locale || 'pt-BR');
        const startText = start.toLocaleTimeString(config.locale || 'pt-BR', { hour: '2-digit', minute: '2-digit' });
        const endText = end instanceof Date
            ? end.toLocaleTimeString(config.locale || 'pt-BR', { hour: '2-digit', minute: '2-digit' })
            : '';

        return endText ? `${dateText} | ${startText} às ${endText}` : `${dateText} | ${startText}`;
    };

    const setupSearchableField = (searchInput, hiddenInput, listId) => {
        const list = document.getElementById(listId);
        if (!searchInput || !hiddenInput || !list) {
            return {
                setByValue: () => {},
                clear: () => {},
            };
        }

        const options = Array.from(list.options).map((option) => ({
            label: option.value,
            value: option.dataset.value || '',
        }));

        const findByLabel = (label) => options.find((option) => option.label.toLowerCase() === String(label || '').trim().toLowerCase());
        const findByValue = (value) => options.find((option) => option.value === String(value || ''));

        const syncFromLabel = () => {
            const match = findByLabel(searchInput.value);
            hiddenInput.value = match ? match.value : '';
            searchInput.setCustomValidity(match || searchInput.value.trim() === '' ? '' : (config.texts.searchSelectionError || ''));
        };

        searchInput.addEventListener('input', syncFromLabel);
        searchInput.addEventListener('change', syncFromLabel);
        searchInput.addEventListener('blur', syncFromLabel);

        syncFromLabel();

        return {
            setByValue: (value) => {
                const match = findByValue(value);
                hiddenInput.value = match ? match.value : '';
                searchInput.value = match ? match.label : '';
                searchInput.setCustomValidity('');
            },
            clear: () => {
                hiddenInput.value = '';
                searchInput.value = '';
                searchInput.setCustomValidity('');
            },
        };
    };

    const syncFormState = (calendar) => {
        const currentView = reverseViewMap[calendar.view.type] || 'week';
        const currentDate = toQueryDate(calendar.getDate());

        syncViewInputs.forEach((input) => {
            input.value = currentView;
        });
        syncDateInputs.forEach((input) => {
            input.value = currentDate;
        });

        const url = `${config.pageUrl}?date=${encodeURIComponent(currentDate)}&view=${encodeURIComponent(currentView)}`;
        window.history.replaceState({}, '', url);
    };

    const setSelectValue = (select, value, label = null) => {
        if (!select) return;
        
        const $select = $(select);
        value = String(value || '0');

        // GLPI AJAX Select2 dropdowns support a custom 'setValue' event
        if ($select.hasClass('select2-hidden-accessible')) {
            if (value !== '0' && value !== '') {
                $select.trigger('setValue', value);
                return;
            }
            // Reset to empty
            $select.val(value).trigger('change');
            return;
        }

        $select.val(value).trigger('change');
    };

    const setTicketSelectValue = (id, label) => {
        if (!ticketInput) return;

        const $select = $(ticketInput);
        const value = String(id || '0');

        if (value === '0' || value === '') {
            $select.val(null).trigger('change');
            return;
        }

        if ($select.find(`option[value='${value}']`).length === 0) {
            $select.append(new Option(label || `#${value}`, value, true, true));
        }

        $select.val(value).trigger('change');
    };

    const applyTicketMetadata = async (ticketId) => {
        if (!ticketId || ticketId === '0') return;

        // Reset if empty
        if (clientContactInput && !clientContactInput.value) {
           clientContactInput.dataset.autofilled = '1';
        }
        
        try {
            const response = await fetch(`${config.actionsUrl}?action=ticket_metadata&ticket_id=${ticketId}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) return;
            
            const data = await response.json();
            
            if (clientContactInput && (!clientContactInput.value || clientContactInput.dataset.autofilled === '1')) {
                clientContactInput.value = data.contact || '';
                clientContactInput.dataset.autofilled = '1';
            }
            if (clientAddressInput && (!clientAddressInput.value || clientAddressInput.dataset.autofilled === '1')) {
                clientAddressInput.value = data.address || '';
                clientAddressInput.dataset.autofilled = '1';
            }
        } catch (e) {
            console.error('Failed to fetch ticket metadata', e);
        }
    };

    const loadAgendamentoHistory = async (agendamentoId) => {
        if (!detailHistory) return;

        detailHistory.innerHTML = `<li class="text-muted">${escapeHtml(config.texts.loadingHistory || 'Carregando...')}</li>`;

        if (!agendamentoId || agendamentoId === '0') {
            detailHistory.innerHTML = `<li class="text-muted">${escapeHtml(config.texts.noHistory || 'Nenhum registro.')}</li>`;
            return;
        }

        try {
            const response = await fetch(`${config.actionsUrl}?action=history&agendamento_id=${agendamentoId}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) throw new Error('request failed');

            const data = await response.json();
            const items = Array.isArray(data.items) ? data.items : [];

            if (items.length === 0) {
                detailHistory.innerHTML = `<li class="text-muted">${escapeHtml(config.texts.noHistory || 'Nenhum registro.')}</li>`;
                return;
            }

            detailHistory.innerHTML = items.map((item) => {
                const date = item.date ? new Date(item.date.replace(' ', 'T')) : null;
                const dateText = date && !Number.isNaN(date.getTime())
                    ? date.toLocaleString(config.locale || 'pt-BR', { dateStyle: 'short', timeStyle: 'short' })
                    : '-';
                const descricao = escapeHtml(item.descricao || '').replaceAll('\n', '<br>');

                return `<li class="mb-2"><strong>${escapeHtml(dateText)}</strong> — ${escapeHtml(item.user || '-')}<br>${descricao}</li>`;
            }).join('');
        } catch (error) {
            detailHistory.innerHTML = `<li class="text-danger">${escapeHtml(config.texts.historyError || 'Erro ao carregar histórico.')}</li>`;
        }
    };

    if (createModal) {
        const form = createModal.querySelector('form');
        if (form && form.dataset.agendamentoSubmitBound !== '1') {
            form.dataset.agendamentoSubmitBound = '1';
            form.addEventListener('submit', () => {
                if (clientContactInput) {
                    delete clientContactInput.dataset.autofilled;
                }
                if (clientAddressInput) {
                    delete clientAddressInput.dataset.autofilled;
                }
            });
        }
    }

    if (ticketInput && !$(ticketInput).hasClass('select2-hidden-accessible')) {
        $(ticketInput).select2({
            width: '100%',
            allowClear: true,
            placeholder: (config.texts && config.texts.selectTicketPlaceholder) || 'Buscar por número ou título do chamado...',
            minimumInputLength: 0,
            ajax: {
                url: config.actionsUrl,
                dataType: 'json',
                delay: 300,
                data: (params) => ({ action: 'ticket_search', term: params.term || '' }),
                processResults: (data) => ({ results: Array.isArray(data.results) ? data.results : [] }),
            },
        });
    }

    if (ticketInput) {
        window.jQuery(ticketInput).on('change', () => {
            applyTicketMetadata(ticketInput.value);
        });
    }

    [clientContactInput, clientAddressInput].forEach((input) => {
        if (!input || input.dataset.agendamentoBound === '1') {
            return;
        }
        input.dataset.agendamentoBound = '1';
        input.addEventListener('input', () => {
            delete input.dataset.autofilled;
        });
    });

    const prefillCreateForm = (start, end) => {
        if (formActionInput) {
            formActionInput.value = 'create';
        }
        if (formIdInput) {
            formIdInput.value = '0';
        }
        if (formTitle) {
            formTitle.textContent = 'Agendar chamado';
        }
        if (formSubmit) {
            formSubmit.textContent = 'Salvar Agendamento';
        }
        setTicketSelectValue(null, null);
        setSelectValue(technicianInput, '0');
        if (statusInput) {
            statusInput.value = 'agendado';
        }
        if (notesInput) {
            notesInput.value = '';
        }
        if (clientContactInput) {
            clientContactInput.value = '';
            clientContactInput.dataset.autofilled = '1';
        }
        if (clientAddressInput) {
            clientAddressInput.value = '';
            clientAddressInput.dataset.autofilled = '1';
        }
        if (startInput) {
            startInput.value = toInputValue(start);
        }
        if (endInput) {
            endInput.value = toInputValue(end || new Date(start.getTime() + 60 * 60 * 1000));
        }
        $(createModal).modal('show');
    };

    const openEditForm = (event) => {
        const props = event.extendedProps || {};
        if (formActionInput) {
            formActionInput.value = 'edit';
        }
        if (formIdInput) {
            formIdInput.value = String(event.id || '0');
        }
        if (formTitle) {
            formTitle.textContent = 'Editar agendamento';
        }
        if (formSubmit) {
            formSubmit.textContent = 'Salvar Alterações';
        }
        
        // Pass event title as label for Select2
        setTicketSelectValue(props.tickets_id || '0', event.title);

        setSelectValue(technicianInput, String(props.users_id_tech || '0'));
        if (statusInput) {
            statusInput.value = String(props.status || 'agendado');
        }
        if (notesInput) {
            notesInput.value = props.notes || '';
        }
        if (clientContactInput) {
            clientContactInput.value = props.clientContact || '';
            clientContactInput.dataset.autofilled = props.clientContact ? '1' : '0';
        }
        if (clientAddressInput) {
            clientAddressInput.value = props.clientAddress || '';
            clientAddressInput.dataset.autofilled = props.clientAddress ? '1' : '0';
        }
        if (startInput && event.start) {
            startInput.value = toInputValue(event.start);
        }
        if (endInput) {
            endInput.value = event.end ? toInputValue(event.end) : toInputValue(new Date(event.start.getTime() + 60 * 60 * 1000));
        }

        $(detailsModal).modal('hide');
        $(createModal).modal('show');
    };

    const promptRescheduleReason = (event) => {
        return new Promise((resolve) => {
            const modal = document.getElementById('plugin-agendamento-reschedule-modal');
            const reasonInput = document.getElementById('plugin-agendamento-reschedule-reason');
            const confirmBtn = document.getElementById('plugin-agendamento-reschedule-confirm-btn');
            const cancelBtn = document.getElementById('plugin-agendamento-reschedule-cancel-btn');
            const infoEl = document.getElementById('plugin-agendamento-reschedule-info');

            if (!modal || !reasonInput || !confirmBtn || !cancelBtn) {
                resolve('');
                return;
            }

            reasonInput.value = '';
            reasonInput.classList.remove('is-invalid');

            if (infoEl) {
                const startText = event.start
                    ? event.start.toLocaleDateString(config.locale || 'pt-BR') + ' ' + event.start.toLocaleTimeString(config.locale || 'pt-BR', { hour: '2-digit', minute: '2-digit' })
                    : '';
                infoEl.textContent = event.title + (startText ? ' → ' + startText : '');
            }

            const cleanup = () => {
                confirmBtn.removeEventListener('click', onConfirm);
                cancelBtn.removeEventListener('click', onCancel);
                $(modal).modal('hide');
            };

            const onConfirm = () => {
                const motivo = reasonInput.value.trim();
                if (!motivo) {
                    reasonInput.classList.add('is-invalid');
                    reasonInput.focus();
                    return;
                }
                reasonInput.classList.remove('is-invalid');
                cleanup();
                resolve(motivo);
            };

            const onCancel = () => {
                cleanup();
                resolve(null);
            };

            confirmBtn.addEventListener('click', onConfirm);
            cancelBtn.addEventListener('click', onCancel);

            $(modal).modal('show');

            $(modal).one('hidden.bs.modal', () => {
                confirmBtn.removeEventListener('click', onConfirm);
                cancelBtn.removeEventListener('click', onCancel);
            });
        });
    };

    const persistEvent = async (info) => {
        const motivo = await promptRescheduleReason(info.event);
        if (motivo === null) {
            info.revert();
            return;
        }

        const params = new URLSearchParams();
        params.set('_glpi_csrf_token', config.csrfToken || '');
        params.set('action', 'reschedule');
        params.set('agendamento_id', info.event.id);
        params.set('tickets_id', info.event.extendedProps.tickets_id || '0');
        params.set('start', info.event.start ? info.event.start.toISOString() : '');
        params.set('end', info.event.end ? info.event.end.toISOString() : '');
        params.set('motivo', motivo);

        const response = await fetch(config.actionsUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': config.csrfToken || '',
            },
            body: params.toString(),
        });

        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            if (response.status === 403) {
                throw new Error(config.texts.csrfError || config.texts.saveError);
            }
            throw new Error(config.texts.saveError);
        }

        const result = await response.json();
        if (result.csrf_token) {
            config.csrfToken = result.csrf_token;
        }
        if (!response.ok || !result.success) {
            throw new Error(result.message || (response.status === 403 ? config.texts.csrfError : config.texts.saveError));
        }
    };

    const applyFilters = (events) => {
        const technicianValue = filterTechnician ? filterTechnician.value : '';
        const statusValue = filterStatus ? filterStatus.value : '';

        return events.filter((event) => {
            const props = event.extendedProps || {};
            const matchesTechnician = !technicianValue || technicianValue === '0' || String(props.users_id_tech || '') === technicianValue;
            const matchesStatus = !statusValue || statusValue === '0' || String(props.status || '') === statusValue;
            return matchesTechnician && matchesStatus;
        });
    };

    const toggleCancelPanel = (visible) => {
        if (!cancelPanel) {
            return;
        }

        cancelPanel.hidden = !visible;

        if (detailActionsLeft) {
            detailActionsLeft.hidden = visible;
        }

        if (detailActionsRight) {
            detailActionsRight.hidden = visible;
        }

        if (cancelReasonInput) {
            cancelReasonInput.disabled = !visible;
            cancelReasonInput.required = visible;
            if (!visible) {
                cancelReasonInput.value = '';
                cancelReasonInput.setCustomValidity('');
            } else {
                window.setTimeout(() => cancelReasonInput.focus(), 0);
            }
        }
    };

        const calendar = new FullCalendar.Calendar(calendarEl, {
        plugins: ['dayGrid', 'interaction', 'timeGrid', 'list'],
        locale: localeKeys.length === 1 ? localeKeys[0] : undefined,
        defaultView: calendarViewMap[config.initialView] || 'timeGridWeek',
        defaultDate: config.initialDate,
        firstDay: 1,
        nowIndicator: true,
        editable: true,
        selectable: true,
        allDaySlot: false,
        dayMaxEvents: true,
        height: config.calendarHeight || 650,
        slotDuration: config.slotDuration || '00:30:00',
        slotLabelInterval: '01:00:00',
        minTime: config.slotMinTime || '07:00:00',
        maxTime: config.slotMaxTime || '21:00:00',
        scrollTime: '08:00:00',
        header: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay',
        },
        columnHeaderFormat: {
            weekday: 'short',
            day: '2-digit',
            month: '2-digit',
            omitCommas: true,
        },
        buttonText: {
            today: config.texts.today,
            month: config.texts.month,
            week: config.texts.week,
            day: config.texts.day,
        },
        eventTimeFormat: {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        },
        events: async (info, successCallback, failureCallback) => {
            try {
                const url = `${config.eventsUrl}&start=${encodeURIComponent(info.startStr)}&end=${encodeURIComponent(info.endStr)}`;
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const events = await response.json();
                successCallback(applyFilters(Array.isArray(events) ? events : []));
            } catch (error) {
                failureCallback(error);
            }
        },
        datesRender: function () {
            syncFormState(calendar);
        },
        select: (info) => {
            prefillCreateForm(info.start, info.end);
            calendar.unselect();
        },
        dateClick: (info) => {
            if (calendar.view.type === 'dayGridMonth') {
                const start = new Date(`${info.dateStr}T09:00:00`);
                const end = new Date(start.getTime() + 60 * 60 * 1000);
                prefillCreateForm(start, end);
            }
        },
        eventClick: (info) => {
            const props = info.event.extendedProps || {};
            selectedEventData = info.event;
            toggleCancelPanel(false);
            detailTitle.textContent = info.event.title || config.texts.detailsTitle;
            detailStatus.textContent = props.statusLabel || '';
            detailStatus.style.backgroundColor = info.event.backgroundColor || '#3b82f6';
            detailStatus.style.color = info.event.textColor || '#ffffff';
            detailTime.textContent = formatEventTime(info.event.start, info.event.end);
            detailTech.textContent = props.technician || config.texts.noTechnician;
            detailContact.textContent = props.clientContact || config.texts.noClientContact;
            detailAddress.textContent = props.clientAddress || config.texts.noClientAddress;
            detailTask.textContent = props.ticketTaskId ? `#${props.ticketTaskId}` : config.texts.noTask;
            detailNotes.textContent = props.notes || config.texts.noNotes;
            detailTicketId.value = String(props.tickets_id || '0');
            detailAgendamentoId.value = String(info.event.id || '0');
            loadAgendamentoHistory(detailAgendamentoId.value);

            if (props.ticketUrl) {
                detailTicketLink.href = props.ticketUrl;
                detailTicketLink.removeAttribute('aria-disabled');
                detailTicketLink.classList.remove('is-disabled');
            } else {
                detailTicketLink.href = '#';
                detailTicketLink.setAttribute('aria-disabled', 'true');
                detailTicketLink.classList.add('is-disabled');
            }

            $(detailsModal).modal('show');
        },
        eventDrop: async (info) => {
            try {
                await persistEvent(info);
            } catch (error) {
                info.revert();
                window.alert(error.message || config.texts.saveError);
            }
        },
        eventResize: async (info) => {
            try {
                await persistEvent(info);
            } catch (error) {
                info.revert();
                window.alert(error.message || config.texts.saveError);
            }
        },
        eventRender: function (info) {
            const props = info.event.extendedProps || {};
            const tooltip = [info.event.title, props.technician, props.statusLabel, props.notes].filter(Boolean).join(' · ');
            const timeText = info.timeText || '';
            const tech = props.technician || '';
            info.el.innerHTML = '<div class="plugin-agendamento-event-inner">'
                + '<div class="plugin-agendamento-event-title">' + escapeHtml(info.event.title) + '</div>'
                + (tech ? '<div class="plugin-agendamento-event-meta">' + escapeHtml(tech) + '</div>' : '')
                + '</div>';
            if (tooltip) {
                info.el.setAttribute('title', tooltip);
            }
        },
    });

        const viewTechAgendaBtn = document.getElementById('plugin-agendamento-view-tech-agenda');

        if (filterTechnician) {
            window.jQuery(filterTechnician).on('change', () => {
                calendar.refetchEvents();
                if (viewTechAgendaBtn) {
                    const techVal = filterTechnician.value;
                    if (techVal && techVal !== '0') {
                        viewTechAgendaBtn.href = config.pageUrl.replace(/\/agendamento\.php.*$/, '/meus_agendamentos.php?tech_id=' + encodeURIComponent(techVal) + '&mode=calendar');
                        viewTechAgendaBtn.style.display = '';
                    } else {
                        viewTechAgendaBtn.style.display = 'none';
                    }
                }
            });
        }

        if (filterStatus) {
            filterStatus.addEventListener('change', () => {
                calendar.refetchEvents();
            });
        }

        window.addEventListener('resize', () => {
            calendar.updateSize();
        });

        if (editButton) {
            editButton.addEventListener('click', () => {
                if (!selectedEventData) {
                    return;
                }
                toggleCancelPanel(false);
                openEditForm(selectedEventData);
            });
        }

        if (cancelToggleButton) {
            cancelToggleButton.addEventListener('click', () => {
                toggleCancelPanel(true);
            });
        }

        if (cancelBackButton) {
            cancelBackButton.addEventListener('click', () => {
                toggleCancelPanel(false);
            });
        }

        calendar.render();
        calendarEl.dataset.agendamentoInitialized = '1';
    };

    const bootstrap = () => {
        bindModalControls();
        initializeCalendar();
    };

    if (document.readyState === 'complete') {
        bootstrap();
    } else {
        window.addEventListener('load', bootstrap, { once: true });
    }
})();
