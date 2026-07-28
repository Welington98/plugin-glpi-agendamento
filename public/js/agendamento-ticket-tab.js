(function () {
    const MODAL_ID = 'plugin-agendamento-tab-form-modal';
    const CANCEL_MODAL_ID = 'plugin-agendamento-tab-cancel-modal';

    const setSelectValue = (select, value) => {
        if (!select) {
            return;
        }

        value = String(value || '0');

        if (typeof window.jQuery === 'function') {
            const $select = window.jQuery(select);
            if ($select.hasClass('select2-hidden-accessible')) {
                if (value !== '0' && value !== '') {
                    $select.trigger('setValue', value);
                    return;
                }
                $select.val(value).trigger('change');
                return;
            }
        }

        select.value = value;
        select.dispatchEvent(new Event('change'));
    };

    const showModal = (modalElement) => {
        if (!modalElement || typeof bootstrap === 'undefined') {
            return;
        }
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    };

    const captureDuration = (startInput, endInput) => {
        if (!startInput || !endInput) {
            return;
        }
        const start = new Date(startInput.value);
        const end = new Date(endInput.value);
        if (!Number.isNaN(start.getTime()) && !Number.isNaN(end.getTime()) && end.getTime() > start.getTime()) {
            endInput.dataset.duration = String(end.getTime() - start.getTime());
        }
    };

    const shiftEndForNewStart = (startInput, endInput) => {
        if (!startInput || !endInput) {
            return;
        }
        const start = new Date(startInput.value);
        if (Number.isNaN(start.getTime())) {
            return;
        }
        const storedDuration = Number.parseInt(endInput.dataset.duration || '', 10);
        const durationMs = Number.isFinite(storedDuration) && storedDuration > 0 ? storedDuration : 60 * 60000;
        const newEnd = new Date(start.getTime() + durationMs);
        const pad = (value) => String(value).padStart(2, '0');
        endInput.value = `${newEnd.getFullYear()}-${pad(newEnd.getMonth() + 1)}-${pad(newEnd.getDate())}T${pad(newEnd.getHours())}:${pad(newEnd.getMinutes())}`;
        endInput.dataset.duration = String(durationMs);
    };

    const getFormFields = (modalElement) => ({
        actionInput: document.getElementById('plugin-agendamento-tab-form-action'),
        idInput: document.getElementById('plugin-agendamento-tab-form-id'),
        titleEl: document.getElementById('plugin-agendamento-tab-form-title'),
        submitLabelEl: document.getElementById('plugin-agendamento-tab-form-submit-label'),
        technicianInput: modalElement ? modalElement.querySelector("select[name='agendamento_users_id_tech']") : null,
        statusInput: document.getElementById('plugin-agendamento-tab-form-status'),
        tipoInput: document.getElementById('plugin-agendamento-tab-form-tipo'),
        notesInput: document.getElementById('plugin-agendamento-tab-form-notes'),
        contactInput: document.getElementById('plugin-agendamento-tab-form-contact'),
        addressInput: document.getElementById('plugin-agendamento-tab-form-address'),
        startInput: document.getElementById('plugin-agendamento-tab-form-start'),
        endInput: document.getElementById('plugin-agendamento-tab-form-end'),
    });

    const resetToCreateMode = () => {
        const modalElement = document.getElementById(MODAL_ID);
        if (!modalElement) {
            return;
        }

        const fields = getFormFields(modalElement);

        if (fields.actionInput) {
            fields.actionInput.value = 'create';
        }
        if (fields.idInput) {
            fields.idInput.value = '0';
        }
        if (fields.titleEl) {
            fields.titleEl.textContent = 'Novo agendamento';
        }
        if (fields.submitLabelEl) {
            fields.submitLabelEl.textContent = 'Salvar agendamento';
        }
        setSelectValue(fields.technicianInput, '0');
        if (fields.statusInput) {
            fields.statusInput.value = 'agendado';
        }
        if (fields.tipoInput) {
            fields.tipoInput.value = '';
        }
        if (fields.notesInput) {
            fields.notesInput.value = '';
        }
        if (fields.contactInput) {
            fields.contactInput.value = fields.contactInput.dataset.default || '';
        }
        if (fields.addressInput) {
            fields.addressInput.value = fields.addressInput.dataset.default || '';
        }
        if (fields.startInput) {
            fields.startInput.value = fields.startInput.dataset.default || '';
        }
        if (fields.endInput) {
            fields.endInput.value = fields.endInput.dataset.default || '';
        }
        captureDuration(fields.startInput, fields.endInput);
    };

    const openCreateModal = () => {
        resetToCreateMode();
        showModal(document.getElementById(MODAL_ID));
    };

    const openEditModal = (card) => {
        const modalElement = document.getElementById(MODAL_ID);
        if (!modalElement || !card) {
            return;
        }

        const fields = getFormFields(modalElement);

        if (fields.actionInput) {
            fields.actionInput.value = 'edit';
        }
        if (fields.idInput) {
            fields.idInput.value = card.dataset.id || '0';
        }
        if (fields.titleEl) {
            fields.titleEl.textContent = 'Editar agendamento';
        }
        if (fields.submitLabelEl) {
            fields.submitLabelEl.textContent = 'Salvar Alterações';
        }
        setSelectValue(fields.technicianInput, card.dataset.tech || '0');
        if (fields.statusInput) {
            fields.statusInput.value = card.dataset.status || 'agendado';
        }
        if (fields.tipoInput) {
            fields.tipoInput.value = card.dataset.tipo || '';
        }
        if (fields.notesInput) {
            fields.notesInput.value = card.dataset.notes || '';
        }
        if (fields.contactInput) {
            fields.contactInput.value = card.dataset.contact || '';
        }
        if (fields.addressInput) {
            fields.addressInput.value = card.dataset.address || '';
        }
        if (fields.startInput) {
            fields.startInput.value = card.dataset.start || '';
        }
        if (fields.endInput) {
            fields.endInput.value = card.dataset.end || '';
        }
        captureDuration(fields.startInput, fields.endInput);

        showModal(modalElement);
    };

    const openCancelModal = (card) => {
        const modalElement = document.getElementById(CANCEL_MODAL_ID);
        if (!modalElement || !card) {
            return;
        }

        const idInput = document.getElementById('plugin-agendamento-tab-cancel-id');
        const reasonInput = document.getElementById('plugin-agendamento-tab-cancel-reason');

        if (idInput) {
            idInput.value = card.dataset.id || '0';
        }
        if (reasonInput) {
            reasonInput.value = '';
        }

        showModal(modalElement);
    };

    const tabFormStartInput = document.getElementById('plugin-agendamento-tab-form-start');
    const tabFormEndInput = document.getElementById('plugin-agendamento-tab-form-end');
    captureDuration(tabFormStartInput, tabFormEndInput);
    if (tabFormEndInput) {
        tabFormEndInput.addEventListener('change', () => captureDuration(tabFormStartInput, tabFormEndInput));
    }
    if (tabFormStartInput) {
        tabFormStartInput.addEventListener('change', () => shiftEndForNewStart(tabFormStartInput, tabFormEndInput));
    }

    document.addEventListener('click', (event) => {
        const newButton = event.target.closest('.plugin-agendamento-tab-new-btn');
        if (newButton) {
            openCreateModal();
            return;
        }

        const editButton = event.target.closest('.plugin-agendamento-tab-edit-btn');
        if (editButton) {
            openEditModal(editButton.closest('.plugin-agendamento-tab-card'));
            return;
        }

        const cancelButton = event.target.closest('.plugin-agendamento-tab-cancel-btn');
        if (cancelButton) {
            openCancelModal(cancelButton.closest('.plugin-agendamento-tab-card'));
        }
    });
})();
