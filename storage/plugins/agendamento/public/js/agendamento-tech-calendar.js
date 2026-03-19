(function () {
    const init = () => {
        const calendarEl = document.getElementById('plugin-agendamento-tech-calendar');
        if (!calendarEl || typeof FullCalendar === 'undefined') {
            return;
        }
        if (calendarEl.dataset.agendamentoInitialized === '1') {
            return;
        }

        let config = {};
        try {
            config = JSON.parse(calendarEl.dataset.config || '{}');
        } catch (e) {
            return;
        }

        const calendarViewMap = {
            day: 'timeGridDay',
            week: 'timeGridWeek',
            month: 'dayGridMonth',
        };

        const calendar = new FullCalendar.Calendar(calendarEl, {
            plugins: ['dayGrid', 'timeGrid', 'interaction'],
            locale: config.locale || 'pt-br',
            defaultView: calendarViewMap[config.initialView] || 'timeGridWeek',
            defaultDate: config.initialDate,
            firstDay: 1,
            nowIndicator: true,
            editable: false,
            selectable: false,
            allDaySlot: false,
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
                today: config.texts?.today || 'Hoje',
                month: config.texts?.month || 'Mês',
                week: config.texts?.week || 'Semana',
                day: config.texts?.day || 'Dia',
            },
            events: {
                url: config.eventsUrl,
                method: 'GET',
                failure: () => {},
            },
            eventRender: (info) => {
                const props = info.event.extendedProps || {};
                const titleEl = info.el.querySelector('.fc-title');
                if (titleEl) {
                    titleEl.innerHTML = '<strong>' + info.event.title + '</strong>';
                }

                const tooltip = [
                    info.event.title,
                    props.statusLabel || '',
                    props.technician ? 'Técnico: ' + props.technician : '',
                ].filter(Boolean).join('\n');
                if (tooltip) {
                    info.el.setAttribute('title', tooltip);
                }
            },
            eventClick: (info) => {
                const props = info.event.extendedProps || {};
                if (props.ticketUrl) {
                    window.open(props.ticketUrl, '_blank');
                }
            },
        });

        calendar.render();
        calendarEl.dataset.agendamentoInitialized = '1';
    };

    if (document.readyState === 'complete') {
        init();
    } else {
        window.addEventListener('load', init, { once: true });
    }
})();
