// Dashboard, persons, doors and groups views.

import {
    api, can, clear, h, field, input, textarea, select, table, pager, badge, boolBadge,
    openModal, confirmModal, toast, reportError, fmtDate, state,
} from '../core.js';

function panel(title, toolbar = null) {
    const head = h('div', { class: 'panel-head' }, h('h2', null, title), toolbar);
    const body = h('div');
    const root = h('section', { class: 'panel' }, head, body);
    return { root, body, head };
}

export async function dashboardView(root) {
    const response = await api('/api/dashboard.php');
    const { stats, today, recent_access } = response.data;
    const cards = h('div', { class: 'grid stats' },
        stat('اشخاص فعال', stats.persons_enabled, `${stats.persons} شخص ثبت شده`),
        stat('کارت‌های فعال', stats.cards),
        stat('درب‌های فعال', stats.doors),
        stat('دستگاه‌های فعال', stats.devices),
        stat('گروه‌های فعال', stats.groups),
        stat('مجوزهای موقت فعال', stats.grants_active),
        stat('تردد مجاز امروز', today.granted || 0, null, 'ok'),
        stat('تردد ردشده امروز', today.denied || 0, null, 'bad')
    );
    const recent = panel('آخرین رخدادهای تردد');
    recent.body.appendChild(table(
        ['زمان', 'شخص', 'درب', 'نتیجه', 'دلیل', 'کارت'],
        recent_access.map((row) => [
            fmtDate(row.occurred_at),
            row.person_name || '—',
            row.door_name || `#${row.door_id ?? '?'}`,
            row.decision === 'granted' ? badge('مجاز', 'ok') : row.decision === 'error' ? badge('خطا', 'warn') : badge('رد شد', 'bad'),
            row.reason,
            row.card_last4 ? `••••${row.card_last4}` : '—',
        ]),
        'هنوز رخدادی ثبت نشده است.'
    ));
    root.append(cards, recent.root);
}

function stat(label, value, hint = null, kind = '') {
    return h('div', { class: 'stat' },
        h('div', { class: 'label' }, label),
        h('div', { class: `value ${kind}` }, String(value)),
        hint ? h('div', { class: 'help' }, hint) : null
    );
}

/* ------------------------------------------------------------- persons --- */

export async function personsView(root, actions) {
    const query = { search: '', page: 1 };
    const section = panel('فهرست اشخاص');
    const search = h('input', { type: 'search', placeholder: 'جستجوی نام…', value: '' });
    const searchButton = h('button', { class: 'btn', type: 'button' }, 'جستجو');
    section.head.appendChild(h('div', { class: 'btn-row' }, searchButton, search));
    if (can('persons.write')) {
        actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: () => openPerson(null) }, '+ افزودن شخص'));
    }
    root.appendChild(section.root);

    async function load() {
        clear(section.body);
        section.body.appendChild(h('div', { class: 'empty' }, 'در حال بارگذاری…'));
        const response = await api('/api/persons.php', { query: { search: query.search, page: query.page, per_page: 25 } });
        clear(section.body);
        const rows = response.data.persons.map((person) => [
            h('div', null,
                h('strong', null, person.name),
                person.notes ? h('div', { class: 'help' }, person.notes) : null),
            person.cards.length
                ? person.cards.map((card) => h('div', { class: 'ltr mono' }, card.card_number,
                    ' ', card.status === 'active' ? badge('فعال', 'ok') : badge(card.status === 'lost' ? 'گمشده' : 'باطل', 'warn')) )
                : '—',
            person.groups.length ? person.groups.map((group) => group.name).join('، ') : '—',
            boolBadge(person.enabled),
            h('div', { class: 'btn-row' },
                can('persons.write') ? h('button', { class: 'btn small', type: 'button', onclick: () => openPerson(person) }, 'ویرایش') : null,
                can('persons.write') ? h('button', {
                    class: 'btn small danger', type: 'button',
                    onclick: async () => {
                        if (!(await confirmModal(`آرشیو «${person.name}» انجام شود؟ کارت‌های او دیگر اجازه ورود نخواهند داشت.`))) return;
                        try {
                            await api('/api/persons.php', { method: 'DELETE', body: { id: person.id } });
                            toast('شخص آرشیو شد.');
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, 'آرشیو') : null)
        ]);
        section.body.appendChild(table(['نام', 'کارت‌ها', 'گروه‌ها', 'وضعیت', 'عملیات'], rows));
        section.body.appendChild(pager(response.meta, (page) => { query.page = page; load().catch(reportError); }));
    }

    function openPerson(person) {
        const isEdit = Boolean(person);
        const nameInput = input({ value: person ? person.name : '', maxLength: 100, required: true });
        const notesInput = textarea({ maxLength: 255, value: person && person.notes ? person.notes : '' });
        const enabled = h('input', { type: 'checkbox', checked: person ? person.enabled : true });
        const cardHost = h('div');
        const form = h('div', { class: 'form-grid' },
            field('نام و نام خانوادگی', nameInput),
            field('یادداشت', notesInput),
            field('وضعیت', h('label', { class: 'check-row' }, enabled, 'شخص فعال است'))
        );
        const modal = openModal({
            title: isEdit ? `ویرایش ${person.name}` : 'افزودن شخص',
            content: h('div', null, form, isEdit ? h('div', { style: { marginTop: '18px' } },
                h('h3', { style: { fontSize: '15px' } }, 'کارت‌ها'), cardHost) : null),
            actions: [
                h('button', { class: 'btn primary', type: 'button', onclick: save }, isEdit ? 'ذخیره' : 'ایجاد'),
                h('button', { class: 'btn', type: 'button', onclick: () => modal.close() }, 'انصراف'),
            ],
        });

        async function save() {
            try {
                const body = {
                    name: nameInput.value.trim(),
                    notes: notesInput.value.trim() === '' ? null : notesInput.value.trim(),
                    enabled: enabled.checked,
                };
                if (isEdit) {
                    await api('/api/persons.php', { method: 'PUT', body: { id: person.id, ...body } });
                    toast('تغییرات ذخیره شد.');
                } else {
                    await api('/api/persons.php', { method: 'POST', body });
                    toast('شخص ایجاد شد.');
                }
                modal.close();
                await load();
            } catch (error) { reportError(error); }
        }

        if (isEdit) {
            cardHost.appendChild(h('div', { class: 'empty' }, 'در حال بارگذاری کارت‌ها…'));
            renderCards();
        }

        async function renderCards() {
            try {
                const response = await api('/api/persons.php', { query: { id: person.id } });
                const cards = response.data.person.cards;
                clear(cardHost);
                if (!cards.length) cardHost.appendChild(h('div', { class: 'notice' }, 'این شخص کارتی ندارد.'));
                for (const card of cards) {
                    const status = select([
                        { value: 'active', label: 'فعال' },
                        { value: 'revoked', label: 'باطل' },
                        { value: 'lost', label: 'گمشده' },
                    ], card.status);
                    status.style.maxWidth = '130px';
                    cardHost.appendChild(h('div', { class: 'btn-row', style: { marginBottom: '8px' } },
                        h('span', { class: 'mono', style: { minWidth: '130px' } }, card.card_number),
                        status,
                        h('button', {
                            class: 'btn small', type: 'button',
                            onclick: async () => {
                                try {
                                    await api('/api/cards.php', { method: 'PUT', body: { id: card.id, status: status.value } });
                                    toast('وضعیت کارت به‌روزرسانی شد.');
                                    await renderCards();
                                } catch (error) { reportError(error); }
                            },
                        }, 'ثبت'),
                        h('button', {
                            class: 'btn small danger', type: 'button',
                            onclick: async () => {
                                if (!(await confirmModal('این کارت حذف شود؟ عملیات قابل بازگشت نیست.'))) return;
                                try {
                                    await api('/api/cards.php', { method: 'DELETE', body: { id: card.id } });
                                    toast('کارت حذف شد.');
                                    await renderCards();
                                } catch (error) { reportError(error); }
                            },
                        }, 'حذف')
                    ));
                }
                const newCard = input({ placeholder: 'شماره کارت جدید', maxLength: 64 });
                newCard.style.maxWidth = '190px';
                cardHost.appendChild(h('div', { class: 'btn-row', style: { marginTop: '10px' } },
                    newCard,
                    h('button', {
                        class: 'btn small primary', type: 'button',
                        onclick: async () => {
                            try {
                                await api('/api/cards.php', {
                                    method: 'POST',
                                    body: { person_id: person.id, card_number: newCard.value.trim() },
                                });
                                toast('کارت اضافه شد.');
                                newCard.value = '';
                                await renderCards();
                            } catch (error) { reportError(error); }
                        },
                    }, 'افزودن کارت')
                ));
            } catch (error) { reportError(error); }
        }
    }

    searchButton.addEventListener('click', () => { query.search = search.value.trim(); query.page = 1; load().catch(reportError); });
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') { event.preventDefault(); searchButton.click(); }
    });
    await load();
}

/* --------------------------------------------------------------- doors --- */

export async function doorsView(root, actions) {
    const section = panel('درب‌های کنترل‌شده');
    if (can('doors.write')) {
        actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: () => openDoor(null) }, '+ افزودن درب'));
    }
    root.appendChild(section.root);

    async function load() {
        clear(section.body);
        const response = await api('/api/doors.php', { query: { include_archived: 1 } });
        clear(section.body);
        const rows = response.data.doors.map((door) => [
            h('div', null, h('strong', null, door.name), door.archived_at ? badge('آرشیو', 'muted') : null),
            door.location || '—',
            `${door.unlock_seconds} ثانیه`,
            boolBadge(door.is_active && !door.archived_at),
            `${door.active_devices} دستگاه`,
            h('div', { class: 'btn-row' },
                can('doors.write') && !door.archived_at ? h('button', { class: 'btn small', type: 'button', onclick: () => openDoor(door) }, 'ویرایش') : null,
                can('doors.write') && !door.archived_at ? h('button', {
                    class: 'btn small', type: 'button',
                    onclick: async () => {
                        try {
                            await api('/api/doors.php', { method: 'PUT', body: { id: door.id, toggle: true } });
                            toast('وضعیت درب تغییر کرد.');
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, door.is_active ? 'غیرفعال‌سازی' : 'فعال‌سازی') : null,
                can('doors.write') && !door.archived_at ? h('button', {
                    class: 'btn small danger', type: 'button',
                    onclick: async () => {
                        if (!(await confirmModal(`درب «${door.name}» آرشیو شود؟`))) return;
                        try {
                            await api('/api/doors.php', { method: 'DELETE', body: { id: door.id } });
                            toast('درب آرشیو شد.');
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, 'آرشیو') : null)
        ]);
        section.body.appendChild(table(['نام', 'محل', 'مدت باز شدن', 'وضعیت', 'دستگاه‌ها', 'عملیات'], rows));
    }

    function openDoor(door) {
        const isEdit = Boolean(door);
        const name = input({ value: door ? door.name : '', maxLength: 100 });
        const location = input({ value: door && door.location ? door.location : '', maxLength: 150 });
        const seconds = h('input', { type: 'number', min: 1, max: 30, value: door ? door.unlock_seconds : 5 });
        const active = h('input', { type: 'checkbox', checked: door ? door.is_active : true });
        const modal = openModal({
            title: isEdit ? `ویرایش ${door.name}` : 'افزودن درب',
            content: h('div', { class: 'form-grid' },
                field('نام درب', name),
                field('محل نصب', location),
                field('مدت باز بودن (۱ تا ۳۰ ثانیه)', seconds),
                field('وضعیت', h('label', { class: 'check-row' }, active, 'درب فعال است'))
            ),
            actions: [
                h('button', { class: 'btn primary', type: 'button', onclick: save }, 'ذخیره'),
                h('button', { class: 'btn', type: 'button', onclick: () => modal.close() }, 'انصراف'),
            ],
        });
        async function save() {
            try {
                const body = {
                    name: name.value.trim(),
                    location: location.value.trim() === '' ? null : location.value.trim(),
                    unlock_seconds: Number(seconds.value),
                    is_active: active.checked,
                };
                if (isEdit) {
                    await api('/api/doors.php', { method: 'PUT', body: { id: door.id, ...body } });
                } else {
                    await api('/api/doors.php', { method: 'POST', body });
                }
                toast('درب ذخیره شد.');
                modal.close();
                await load();
            } catch (error) { reportError(error); }
        }
    }

    await load();
}

/* -------------------------------------------------------------- groups --- */

export async function groupsView(root, actions) {
    const section = panel('گروه‌های دسترسی');
    if (can('groups.write')) {
        actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: () => openGroup(null) }, '+ افزودن گروه'));
    }
    root.appendChild(section.root);

    async function load() {
        clear(section.body);
        const response = await api('/api/groups.php', { query: { per_page: 100, include_archived: 1 } });
        clear(section.body);
        const rows = response.data.groups.map((group) => [
            h('div', null, h('strong', null, group.name), group.description ? h('div', { class: 'help' }, group.description) : null),
            group.members.length ? group.members.map((member) => member.name).join('، ') : '—',
            group.doors.length ? group.doors.map((door) => door.name).join('، ') : '—',
            boolBadge(group.enabled),
            h('div', { class: 'btn-row' },
                can('groups.write') && !group.archived_at ? h('button', { class: 'btn small', type: 'button', onclick: () => openGroup(group) }, 'ویرایش') : null,
                can('groups.write') && !group.archived_at ? h('button', {
                    class: 'btn small danger', type: 'button',
                    onclick: async () => {
                        if (!(await confirmModal(`گروه «${group.name}» آرشیو شود؟`))) return;
                        try {
                            await api('/api/groups.php', { method: 'DELETE', body: { id: group.id } });
                            toast('گروه آرشیو شد.');
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, 'آرشیو') : null)
        ]);
        section.body.appendChild(table(['نام', 'اعضا', 'درب‌ها', 'وضعیت', 'عملیات'], rows));
    }

    async function openGroup(group) {
        const isEdit = Boolean(group);
        const [personsResponse, doorsResponse] = await Promise.all([
            api('/api/persons.php', { query: { per_page: 200 } }),
            api('/api/doors.php'),
        ]);
        const name = input({ value: group ? group.name : '', maxLength: 100 });
        const description = input({ value: group && group.description ? group.description : '', maxLength: 255 });
        const enabled = h('input', { type: 'checkbox', checked: group ? group.enabled : true });
        const memberIds = new Set(group ? group.members.map((member) => member.id) : []);
        const doorIds = new Set(group ? group.doors.map((door) => door.id) : []);
        const memberChecks = personsResponse.data.persons.map((person) => {
            const checkbox = h('input', { type: 'checkbox', checked: memberIds.has(person.id) });
            checkbox.dataset.id = String(person.id);
            return h('label', { class: 'check-row' }, checkbox, person.name);
        });
        const doorChecks = doorsResponse.data.doors.filter((door) => !door.archived_at).map((door) => {
            const checkbox = h('input', { type: 'checkbox', checked: doorIds.has(door.id) });
            checkbox.dataset.id = String(door.id);
            return h('label', { class: 'check-row' }, checkbox, door.name);
        });
        const memberBox = h('div', { class: 'check-grid' }, memberChecks.length ? memberChecks : 'شخصی وجود ندارد.');
        const doorBox = h('div', { class: 'check-grid' }, doorChecks.length ? doorChecks : 'دربی وجود ندارد.');
        const modal = openModal({
            title: isEdit ? `ویرایش ${group.name}` : 'افزودن گروه',
            wide: true,
            content: h('div', null,
                h('div', { class: 'form-grid' },
                    field('نام گروه', name),
                    field('توضیح', description),
                    field('وضعیت', h('label', { class: 'check-row' }, enabled, 'گروه فعال است'))
                ),
                h('h3', { style: { fontSize: '15px', marginTop: '18px' } }, 'اعضا'),
                memberBox,
                h('h3', { style: { fontSize: '15px', marginTop: '18px' } }, 'درب‌های مجاز'),
                doorBox
            ),
            actions: [
                h('button', { class: 'btn primary', type: 'button', onclick: save }, 'ذخیره'),
                h('button', { class: 'btn', type: 'button', onclick: () => modal.close() }, 'انصراف'),
            ],
        });
        async function save() {
            const body = {
                name: name.value.trim(),
                description: description.value.trim() === '' ? null : description.value.trim(),
                enabled: enabled.checked,
                member_ids: Array.from(memberBox.querySelectorAll('input:checked')).map((el) => Number(el.dataset.id)),
                door_ids: Array.from(doorBox.querySelectorAll('input:checked')).map((el) => Number(el.dataset.id)),
            };
            try {
                if (isEdit) {
                    await api('/api/groups.php', { method: 'PUT', body: { id: group.id, ...body } });
                } else {
                    await api('/api/groups.php', { method: 'POST', body });
                }
                toast('گروه ذخیره شد.');
                modal.close();
                await load();
            } catch (error) { reportError(error); }
        }
    }

    await load();
}
