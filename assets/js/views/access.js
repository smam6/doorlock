// Schedules, explicit permissions, temporary grants and access-log views.

import {
    api, can, clear, h, field, input, textarea, select, table, pager, badge, boolBadge,
    openModal, confirmModal, toast, reportError, fmtDate, weekdaysPicker, reasonLabel,
    decisionBadge, state,
} from '../core.js';

const DAY_NAMES = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
const daysLabel = (csv) => String(csv || '').split(',').filter(Boolean).map((n) => DAY_NAMES[Number(n) - 1] || n).join('، ');

async function options() {
    const [personsResponse, doorsResponse] = await Promise.all([
        api('/api/persons.php', { query: { per_page: 200 } }),
        api('/api/doors.php'),
    ]);
    return {
        persons: personsResponse.data.persons.filter((person) => !person.archived_at),
        doors: doorsResponse.data.doors.filter((door) => !door.archived_at),
    };
}

function panel(title, toolbar = null) {
    return h('section', { class: 'panel' },
        h('div', { class: 'panel-head' }, h('h2', null, title), toolbar),
        h('div', { class: 'panel-body' }));
}

/* ----------------------------------------------------------- schedules --- */

export async function schedulesView(root, actions) {
    const { persons, doors } = await options();
    const personById = new Map(persons.map((person) => [person.id, person]));
    const doorById = new Map(doors.map((door) => [door.id, door]));
    const filterPerson = select([{ value: '', label: 'همه اشخاص' }, ...persons.map((p) => ({ value: p.id, label: p.name }))]);
    const filterDoor = select([{ value: '', label: 'همه درب‌ها' }, ...doors.map((d) => ({ value: d.id, label: d.name }))]);
    const section = panel('برنامه‌های زمانی', h('div', { class: 'btn-row' }, filterPerson, filterDoor));
    const body = section.querySelector('.panel-body');
    if (can('schedules.write')) {
        actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: () => openSchedule(null) }, '+ افزودن برنامه'));
    }
    root.appendChild(section);

    async function load() {
        clear(body);
        const response = await api('/api/schedules.php', {
            query: { per_page: 100, person_id: filterPerson.value, door_id: filterDoor.value },
        });
        clear(body);
        const rows = response.data.schedules.map((schedule) => [
            schedule.person_name || (personById.get(schedule.person_id) || {}).name || `#${schedule.person_id}`,
            schedule.door_name || (doorById.get(schedule.door_id) || {}).name || `#${schedule.door_id}`,
            daysLabel(schedule.days),
            `${schedule.start_time} – ${schedule.end_time}`,
            boolBadge(schedule.enabled),
            h('div', { class: 'btn-row' },
                can('schedules.write') ? h('button', { class: 'btn small', type: 'button', onclick: () => openSchedule(schedule) }, 'ویرایش') : null,
                can('schedules.write') ? h('button', {
                    class: 'btn small danger', type: 'button',
                    onclick: async () => {
                        if (!(await confirmModal('این برنامه زمانی حذف شود؟'))) return;
                        try {
                            await api('/api/schedules.php', { method: 'DELETE', body: { id: schedule.id } });
                            toast('برنامه حذف شد.');
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, 'حذف') : null)
        ]);
        body.appendChild(table(['شخص', 'درب', 'روزها', 'ساعت', 'وضعیت', 'عملیات'], rows));
    }

    function openSchedule(schedule) {
        const isEdit = Boolean(schedule);
        const person = select(persons.map((p) => ({ value: p.id, label: p.name })), schedule ? schedule.person_id : persons[0]?.id);
        const door = select(doors.map((d) => ({ value: d.id, label: d.name })), schedule ? schedule.door_id : doors[0]?.id);
        const picker = weekdaysPicker(schedule ? String(schedule.days).split(',').map(Number) : [1, 2, 3, 4, 5]);
        const start = h('input', { type: 'time', value: schedule ? schedule.start_time : '08:00' });
        const end = h('input', { type: 'time', value: schedule ? schedule.end_time : '18:00' });
        const enabled = h('input', { type: 'checkbox', checked: schedule ? schedule.enabled : true });
        const modal = openModal({
            title: isEdit ? 'ویرایش برنامه زمانی' : 'افزودن برنامه زمانی',
            content: h('div', null,
                h('div', { class: 'form-grid' },
                    field('شخص', isEdit ? h('div', { class: 'notice' }, schedule.person_name || person.selectedOptions[0]?.textContent || '') : person),
                    field('درب', isEdit ? h('div', { class: 'notice' }, schedule.door_name || door.selectedOptions[0]?.textContent || '') : door),
                    field('ساعت شروع', start),
                    field('ساعت پایان', end),
                    field('وضعیت', h('label', { class: 'check-row' }, enabled, 'برنامه فعال است'))
                ),
                h('h3', { style: { fontSize: '15px', marginTop: '16px' } }, 'روزهای هفته'),
                picker,
                h('div', { class: 'help', style: { marginTop: '8px' } },
                    'اگر ساعت شروع و پایان برابر باشد، دسترسی تمام شبانه‌روز است. بازه‌ای که از نیمه‌شب عبور کند، به روز بعد منتقل می‌شود.')
            ),
            actions: [
                h('button', { class: 'btn primary', type: 'button', onclick: save }, 'ذخیره'),
                h('button', { class: 'btn', type: 'button', onclick: () => modal.close() }, 'انصراف'),
            ],
        });
        async function save() {
            const days = picker.values();
            if (!days.length) { toast('حداقل یک روز را انتخاب کنید.', 'warn'); return; }
            const body = { days, start_time: start.value, end_time: end.value, enabled: enabled.checked };
            try {
                if (isEdit) {
                    await api('/api/schedules.php', { method: 'PUT', body: { id: schedule.id, ...body } });
                } else {
                    await api('/api/schedules.php', { method: 'POST', body: { person_id: Number(person.value), door_id: Number(door.value), ...body } });
                }
                toast('برنامه ذخیره شد.');
                modal.close();
                await load();
            } catch (error) { reportError(error); }
        }
    }

    filterPerson.addEventListener('change', () => load().catch(reportError));
    filterDoor.addEventListener('change', () => load().catch(reportError));
    await load();
}

/* --------------------------------------------------------- permissions --- */

export async function permissionsView(root, actions) {
    const { persons, doors } = await options();
    const filterPerson = select([{ value: '', label: 'همه اشخاص' }, ...persons.map((p) => ({ value: p.id, label: p.name }))]);
    const section = panel('مجوزهای صریح بر اساس شخص و درب', filterPerson);
    const body = section.querySelector('.panel-body');
    if (can('permissions.write')) {
        actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: () => openPermission() }, '+ افزودن مجوز'));
    }
    root.appendChild(section);

    async function load() {
        clear(body);
        const response = await api('/api/permissions.php', { query: { per_page: 200, person_id: filterPerson.value } });
        clear(body);
        const rows = response.data.permissions.map((permission) => [
            permission.person_name,
            permission.door_name,
            permission.effect === 'allow' ? badge('اجازه', 'ok') : badge('منع', 'bad'),
            h('div', { class: 'btn-row' },
                can('permissions.write') ? h('button', {
                    class: 'btn small danger', type: 'button',
                    onclick: async () => {
                        if (!(await confirmModal('این مجوز حذف شود؟'))) return;
                        try {
                            await api('/api/permissions.php', {
                                method: 'DELETE',
                                body: { person_id: permission.person_id, door_id: permission.door_id },
                            });
                            toast('مجوز حذف شد.');
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, 'حذف') : null)
        ]);
        body.appendChild(table(['شخص', 'درب', 'نوع', 'عملیات'], rows));
    }

    function openPermission() {
        const person = select(persons.map((p) => ({ value: p.id, label: p.name })));
        const door = select(doors.map((d) => ({ value: d.id, label: d.name })));
        const effect = select([
            { value: 'allow', label: 'اجازه صریح (حتی بدون گروه)' },
            { value: 'deny', label: 'منع صریح (بر همه مجوزها مقدم است)' },
        ]);
        const modal = openModal({
            title: 'افزودن مجوز صریح',
            content: h('div', { class: 'form-grid' },
                field('شخص', person),
                field('درب', door),
                field('نوع مجوز', effect)
            ),
            actions: [
                h('button', {
                    class: 'btn primary', type: 'button',
                    onclick: async () => {
                        try {
                            await api('/api/permissions.php', {
                                method: 'POST',
                                body: { person_id: Number(person.value), door_id: Number(door.value), effect: effect.value },
                            });
                            toast('مجوز ذخیره شد.');
                            modal.close();
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, 'ذخیره'),
                h('button', { class: 'btn', type: 'button', onclick: () => modal.close() }, 'انصراف'),
            ],
        });
    }

    filterPerson.addEventListener('change', () => load().catch(reportError));
    await load();
}

/* -------------------------------------------------------------- grants --- */

export async function grantsView(root, actions) {
    const { persons, doors } = await options();
    const filterState = select([
        { value: 'all', label: 'همه وضعیت‌ها' },
        { value: 'active', label: 'فعال' },
        { value: 'scheduled', label: 'زمان‌بندی‌شده' },
        { value: 'expired', label: 'منقضی' },
        { value: 'exhausted', label: 'سهمیه تمام‌شده' },
        { value: 'revoked', label: 'لغو‌شده' },
    ]);
    const section = panel('مجوزهای موقت', filterState);
    const body = section.querySelector('.panel-body');
    if (can('grants.write')) {
        actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: () => openGrant() }, '+ افزودن مجوز موقت'));
    }
    root.appendChild(section);

    const stateBadge = (value) => ({
        active: badge('فعال', 'ok'),
        scheduled: badge('زمان‌بندی‌شده', 'warn'),
        expired: badge('منقضی', 'muted'),
        exhausted: badge('سهمیه تمام', 'warn'),
        revoked: badge('لغو‌شده', 'bad'),
    })[value] || badge(value, 'muted');

    async function load() {
        clear(body);
        const response = await api('/api/grants.php', { query: { state: filterState.value, per_page: 100 } });
        clear(body);
        const rows = response.data.grants.map((grant) => [
            grant.person_name,
            grant.door_name,
            fmtDate(grant.valid_from),
            fmtDate(grant.valid_to),
            grant.max_uses === null ? 'نامحدود' : `${grant.used_count} از ${grant.max_uses}`,
            stateBadge(grant.state),
            h('div', { class: 'btn-row' },
                can('grants.write') && grant.state !== 'revoked' ? h('button', {
                    class: 'btn small danger', type: 'button',
                    onclick: async () => {
                        if (!(await confirmModal('این مجوز موقت لغو شود؟'))) return;
                        try {
                            await api('/api/grants.php', { method: 'DELETE', body: { id: grant.id } });
                            toast('مجوز لغو شد.');
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, 'لغو') : null)
        ]);
        body.appendChild(table(['شخص', 'درب', 'از', 'تا', 'مصرف', 'وضعیت', 'عملیات'], rows));
    }

    function openGrant() {
        const person = select(persons.map((p) => ({ value: p.id, label: p.name })));
        const door = select(doors.map((d) => ({ value: d.id, label: d.name })));
        const from = h('input', { type: 'datetime-local' });
        const to = h('input', { type: 'datetime-local' });
        const maxUses = h('input', { type: 'number', min: 1, max: 100000, placeholder: 'خالی = نامحدود' });
        const note = input({ maxLength: 255 });
        const modal = openModal({
            title: 'افزودن مجوز موقت',
            content: h('div', null,
                h('div', { class: 'form-grid' },
                    field('شخص', person),
                    field('درب', door),
                    field('از (زمان محلی سایت)', from),
                    field('تا (زمان محلی سایت)', to),
                    field('حداکثر تعداد استفاده', maxUses),
                    field('یادداشت', note)
                ),
                h('div', { class: 'help', style: { marginTop: '10px' } },
                    'مجوز موقت بر برنامه‌های زمانی مقدم است و در صورت تعیین سهمیه، به‌صورت اتمیک مصرف می‌شود.')
            ),
            actions: [
                h('button', {
                    class: 'btn primary', type: 'button',
                    onclick: async () => {
                        try {
                            await api('/api/grants.php', {
                                method: 'POST',
                                body: {
                                    person_id: Number(person.value),
                                    door_id: Number(door.value),
                                    valid_from: from.value,
                                    valid_to: to.value,
                                    max_uses: maxUses.value === '' ? null : Number(maxUses.value),
                                    note: note.value.trim() === '' ? null : note.value.trim(),
                                },
                            });
                            toast('مجوز موقت ایجاد شد.');
                            modal.close();
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, 'ایجاد'),
                h('button', { class: 'btn', type: 'button', onclick: () => modal.close() }, 'انصراف'),
            ],
        });
    }

    filterState.addEventListener('change', () => load().catch(reportError));
    await load();
}

/* ---------------------------------------------------------------- logs --- */

export async function logsView(root, actions) {
    const { doors } = await options();
    const from = h('input', { type: 'date' });
    const to = h('input', { type: 'date' });
    const door = select([{ value: '', label: 'همه درب‌ها' }, ...doors.map((d) => ({ value: d.id, label: d.name }))]);
    const decision = select([
        { value: '', label: 'همه نتایج' },
        { value: 'granted', label: 'مجاز' },
        { value: 'denied', label: 'ردشده' },
        { value: 'error', label: 'خطا' },
    ]);
    const applyButton = h('button', { class: 'btn', type: 'button' }, 'اعمال فیلتر');
    const exportButton = h('button', { class: 'btn', type: 'button' }, 'خروجی CSV');
    const section = panel('گزارش تردد', h('div', { class: 'btn-row' }, applyButton, exportButton));
    const filters = h('div', { class: 'toolbar' },
        h('div', { class: 'form-row' }, h('label', null, 'از تاریخ'), from),
        h('div', { class: 'form-row' }, h('label', null, 'تا تاریخ'), to),
        h('div', { class: 'form-row' }, h('label', null, 'درب'), door),
        h('div', { class: 'form-row' }, h('label', null, 'نتیجه'), decision)
    );
    const body = section.querySelector('.panel-body');
    section.insertBefore(filters, body);
    root.appendChild(section);
    let page = 1;

    const query = () => ({ from: from.value, to: to.value, door_id: door.value, decision: decision.value, page });

    async function load() {
        clear(body);
        const response = await api('/api/logs.php', { query: { ...query(), per_page: 50 } });
        clear(body);
        const rows = response.data.logs.map((log) => [
            fmtDate(log.occurred_at),
            log.person_name || '—',
            log.door_name || '—',
            decisionBadge(log),
            reasonLabel(log.reason),
            log.card_last4 ? `••••${log.card_last4}` : '—',
            h('span', { class: 'mono', style: { fontSize: '11px' } }, log.request_id)
        ]);
        body.appendChild(table(['زمان', 'شخص', 'درب', 'نتیجه', 'دلیل', 'کارت', 'شناسه درخواست'], rows));
        body.appendChild(pager(response.meta, (next) => { page = next; load().catch(reportError); }));
    }

    applyButton.addEventListener('click', () => { page = 1; load().catch(reportError); });
    exportButton.addEventListener('click', async () => {
        try {
            const params = new URLSearchParams({ format: 'csv', ...Object.fromEntries(Object.entries(query()).filter(([, v]) => v !== '' && v !== null)) });
            const response = await fetch(`/api/logs.php?${params}`, { credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const blob = await response.blob();
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `access-log-${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(link.href);
        } catch (error) { reportError(error); }
    });
    await load();
}
