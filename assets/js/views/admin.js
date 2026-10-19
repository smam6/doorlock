// Devices, web users, audit trail and own-account views.

import {
    api, can, clear, h, field, input, select, table, pager, badge, boolBadge,
    openModal, confirmModal, toast, reportError, fmtDate, roleLabel, state,
} from '../core.js';

function panel(title, toolbar = null) {
    return h('section', { class: 'panel' },
        h('div', { class: 'panel-head' }, h('h2', null, title), toolbar),
        h('div', { class: 'panel-body' }));
}

function randomDeviceId() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

async function doorOptions() {
    const response = await api('/api/doors.php');
    return response.data.doors.filter((door) => !door.archived_at);
}

/* ------------------------------------------------------------- devices --- */

export async function devicesView(root, actions) {
    const doors = await doorOptions();
    const section = panel('دستگاه‌های کنترل تردد');
    const body = section.querySelector('.panel-body');
    if (can('devices.write')) {
        actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: () => openDevice() }, '+ ثبت دستگاه'));
    }
    root.appendChild(section);

    async function load() {
        clear(body);
        const response = await api('/api/devices.php', { query: { include_inactive: 1 } });
        clear(body);
        const rows = response.data.devices.map((device) => [
            h('div', null, h('strong', null, device.name), h('div', { class: 'help mono' }, device.unique_id)),
            device.door_name || `#${device.door_id}`,
            String(device.key_version),
            boolBadge(device.is_active),
            device.last_seen_at ? fmtDate(device.last_seen_at) : 'هرگز',
            h('div', { class: 'btn-row' },
                can('devices.write') ? h('button', {
                    class: 'btn small', type: 'button',
                    onclick: async () => {
                        try {
                            const response = await api('/api/devices.php', { method: 'POST', body: { action: 'provision', id: device.id } });
                            showKey(response.data.provisioning, device.name);
                        } catch (error) { reportError(error); }
                    },
                }, 'کلید دستگاه') : null,
                can('devices.write') ? h('button', {
                    class: 'btn small', type: 'button',
                    onclick: async () => {
                        if (!(await confirmModal('کلید دستگاه چرخانده شود؟ دستگاه فعلی تا به‌روزرسانی پیکربندی از کار می‌افتد.'))) return;
                        try {
                            const response = await api('/api/devices.php', { method: 'PUT', body: { id: device.id, rotate_key: true } });
                            toast('کلید چرخانده شد.');
                            await load();
                            if (response.data.device_key) {
                                showKey({
                                    device_id: device.id,
                                    unique_id: device.unique_id,
                                    door_id: device.door_id,
                                    key_version: device.key_version + 1,
                                    device_key: response.data.device_key,
                                    endpoint: '/api/check-access.php',
                                }, device.name);
                            }
                        } catch (error) { reportError(error); }
                    },
                }, 'چرخش کلید') : null,
                can('devices.write') ? h('button', {
                    class: 'btn small', type: 'button',
                    onclick: async () => {
                        try {
                            await api('/api/devices.php', { method: 'PUT', body: { id: device.id, toggle: true } });
                            toast('وضعیت دستگاه تغییر کرد.');
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, device.is_active ? 'غیرفعال' : 'فعال') : null)
        ]);
        body.appendChild(table(['نام / شناسه', 'درب', 'نسخه کلید', 'وضعیت', 'آخرین اتصال', 'عملیات'], rows));
    }

    function showKey(provisioning, deviceName) {
        const keyBox = h('input', { type: 'text', value: provisioning.device_key, readOnly: true, class: 'mono' });
        const modal = openModal({
            title: `کلید دستگاه — ${deviceName}`,
            wide: true,
            content: h('div', null,
                h('div', { class: 'notice' },
                    'این کلید یک راز است. آن را فقط در پیکربندی دستگاه قرار دهید و در جای دیگری ذخیره نکنید. ',
                    'کلید از روی کلید برنامه مشتق می‌شود و با «چرخش کلید» نسخه قبلی بی‌درنگ باطل می‌شود.'),
                h('div', { class: 'kv', style: { marginTop: '14px' } },
                    h('dt', null, 'شناسه دستگاه'), h('dd', { class: 'mono' }, provisioning.unique_id),
                    h('dt', null, 'شناسه درب'), h('dd', null, String(provisioning.door_id)),
                    h('dt', null, 'نسخه کلید'), h('dd', null, String(provisioning.key_version)),
                    h('dt', null, 'مسیر API'), h('dd', { class: 'mono' }, provisioning.endpoint),
                    h('dt', null, 'کلید دستگاه'), h('dd', null, keyBox)
                ),
                h('div', { class: 'help', style: { marginTop: '12px' } },
                    'پروتکل امضا: HMAC-SHA256 روی رشته استاندارد DOORLOCK1؛ جزئیات در docs/DEVICE_PROTOCOL.md آمده است.')
            ),
            actions: [
                h('button', {
                    class: 'btn primary', type: 'button',
                    onclick: async () => {
                        try {
                            keyBox.select();
                            await navigator.clipboard.writeText(provisioning.device_key);
                            toast('کلید در کلیپ‌بورد کپی شد.');
                        } catch {
                            keyBox.select();
                            toast('کلید انتخاب شد؛ با Ctrl+C کپی کنید.', 'warn');
                        }
                    },
                }, 'کپی کلید'),
                h('button', { class: 'btn', type: 'button', onclick: () => modal.close() }, 'بستن'),
            ],
        });
    }

    function openDevice() {
        const name = input({ maxLength: 100 });
        const uid = input({ value: randomDeviceId(), maxLength: 32, class: 'mono' });
        const door = select(doors.map((d) => ({ value: d.id, label: d.name })));
        const modal = openModal({
            title: 'ثبت دستگاه جدید',
            content: h('div', null,
                h('div', { class: 'form-grid' },
                    field('نام دستگاه', name),
                    field('شناسه یکتا (۳۲ رقم هگز)', uid),
                    field('درب متصل', door)
                ),
                h('div', { class: 'help', style: { marginTop: '10px' } },
                    'شناسه یکتا را می‌توانید نگه دارید یا دوباره تولید کنید. پس از ثبت، کلید دستگاه فقط یک بار نمایش داده می‌شود.')
            ),
            actions: [
                h('button', {
                    class: 'btn', type: 'button',
                    onclick: () => { uid.value = randomDeviceId(); },
                }, 'تولید شناسه جدید'),
                h('button', {
                    class: 'btn primary', type: 'button',
                    onclick: async () => {
                        try {
                            const response = await api('/api/devices.php', {
                                method: 'POST',
                                body: { name: name.value.trim(), unique_id: uid.value.trim(), door_id: Number(door.value) },
                            });
                            modal.close();
                            await load();
                            showKey({
                                device_id: response.data.device.id,
                                unique_id: response.data.device.unique_id,
                                door_id: response.data.device.door_id,
                                key_version: response.data.device.key_version,
                                device_key: response.data.device_key,
                                endpoint: '/api/check-access.php',
                            }, response.data.device.name);
                        } catch (error) { reportError(error); }
                    },
                }, 'ثبت و نمایش کلید'),
                h('button', { class: 'btn', type: 'button', onclick: () => modal.close() }, 'انصراف'),
            ],
        });
    }

    await load();
}

/* --------------------------------------------------------------- users --- */

export async function usersView(root, actions) {
    const section = panel('کاربران پنل مدیریت');
    const body = section.querySelector('.panel-body');
    if (can('users.write')) {
        actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: () => openUser(null) }, '+ افزودن کاربر'));
    }
    root.appendChild(section);

    async function load() {
        clear(body);
        const response = await api('/api/users.php');
        clear(body);
        const rows = response.data.users.map((user) => [
            h('span', { class: 'mono' }, user.username),
            roleLabel(user.role),
            boolBadge(user.is_active),
            user.last_login_at ? fmtDate(user.last_login_at) : 'هرگز',
            h('div', { class: 'btn-row' },
                can('users.write') ? h('button', { class: 'btn small', type: 'button', onclick: () => openUser(user) }, 'ویرایش') : null,
                can('users.write') && user.id !== state.user.id ? h('button', {
                    class: 'btn small danger', type: 'button',
                    onclick: async () => {
                        if (!(await confirmModal(`حساب «${user.username}» غیرفعال شود؟`))) return;
                        try {
                            await api('/api/users.php', { method: 'DELETE', body: { id: user.id } });
                            toast('کاربر غیرفعال شد.');
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, 'غیرفعال‌سازی') : null)
        ]);
        body.appendChild(table(['نام کاربری', 'نقش', 'وضعیت', 'آخرین ورود', 'عملیات'], rows));
    }

    function openUser(user) {
        const isEdit = Boolean(user);
        const username = input({ value: user ? user.username : '', maxLength: 64, disabled: isEdit });
        const role = select([
            { value: 'admin', label: 'مدیر' },
            { value: 'operator', label: 'اپراتور' },
            { value: 'viewer', label: 'بازدیدکننده' },
        ], user ? user.role : 'viewer');
        const active = h('input', { type: 'checkbox', checked: user ? user.is_active : true, disabled: user && user.id === state.user.id });
        const password = h('input', { type: 'password', autocomplete: 'new-password', placeholder: isEdit ? 'برای تغییر، گذرواژه جدید' : 'حداقل ۱۲ نویسه' });
        const modal = openModal({
            title: isEdit ? `ویرایش ${user.username}` : 'افزودن کاربر',
            content: h('div', { class: 'form-grid' },
                field('نام کاربری', username, 'حروف کوچک، عدد، نقطه، خط تیره و زیرخط؛ ۳ تا ۶۴ نویسه.'),
                field(isEdit ? 'گذرواژه جدید (اختیاری)' : 'گذرواژه', password,
                    'حداقل ۱۲ نویسه و شامل نام کاربری نباشد.'),
                field('نقش', role),
                field('وضعیت', h('label', { class: 'check-row' }, active, 'حساب فعال است'))
            ),
            actions: [
                h('button', {
                    class: 'btn primary', type: 'button',
                    onclick: async () => {
                        try {
                            if (isEdit) {
                                const body = { id: user.id, role: role.value, is_active: active.checked };
                                if (password.value !== '') body.password = password.value;
                                await api('/api/users.php', { method: 'PUT', body });
                            } else {
                                await api('/api/users.php', {
                                    method: 'POST',
                                    body: { username: username.value.trim(), password: password.value, role: role.value },
                                });
                            }
                            toast('کاربر ذخیره شد.');
                            modal.close();
                            await load();
                        } catch (error) { reportError(error); }
                    },
                }, 'ذخیره'),
                h('button', { class: 'btn', type: 'button', onclick: () => modal.close() }, 'انصراف'),
            ],
        });
    }

    await load();
}

/* --------------------------------------------------------------- audit --- */

export async function auditView(root) {
    const action = input({ placeholder: 'مثلاً persons.create' });
    const entity = input({ placeholder: 'person، door، …' });
    const from = h('input', { type: 'date' });
    const to = h('input', { type: 'date' });
    const apply = h('button', { class: 'btn', type: 'button' }, 'اعمال فیلتر');
    const section = panel('گزارش ممیزی', apply);
    const filters = h('div', { class: 'toolbar' },
        h('div', { class: 'form-row' }, h('label', null, 'کنش'), action),
        h('div', { class: 'form-row' }, h('label', null, 'موجودیت'), entity),
        h('div', { class: 'form-row' }, h('label', null, 'از تاریخ'), from),
        h('div', { class: 'form-row' }, h('label', null, 'تا تاریخ'), to)
    );
    const body = section.querySelector('.panel-body');
    section.insertBefore(filters, body);
    root.appendChild(section);
    let page = 1;

    async function load() {
        clear(body);
        const response = await api('/api/audit.php', {
            query: { action: action.value.trim(), entity_type: entity.value.trim(), from: from.value, to: to.value, page, per_page: 50 },
        });
        clear(body);
        const rows = response.data.entries.map((entry) => [
            fmtDate(entry.created_at),
            entry.actor_username || '—',
            h('span', { class: 'mono' }, entry.action),
            `${entry.entity_type}${entry.entity_id ? ' #' + entry.entity_id : ''}`,
            h('details', null,
                h('summary', { class: 'help' }, 'جزئیات'),
                h('pre', { class: 'mono', style: { whiteSpace: 'pre-wrap', fontSize: '11px' } },
                    JSON.stringify({ before: entry.before, after: entry.after }, null, 1))),
            h('span', { class: 'mono', style: { fontSize: '11px' } }, entry.request_id)
        ]);
        body.appendChild(table(['زمان', 'کاربر', 'کنش', 'موجودیت', 'تغییرات', 'شناسه درخواست'], rows));
        body.appendChild(pager(response.meta, (next) => { page = next; load().catch(reportError); }));
    }

    apply.addEventListener('click', () => { page = 1; load().catch(reportError); });
    await load();
}

/* ------------------------------------------------------------- profile --- */

export async function profileView(root) {
    const current = h('input', { type: 'password', autocomplete: 'current-password' });
    const next = h('input', { type: 'password', autocomplete: 'new-password' });
    const repeat = h('input', { type: 'password', autocomplete: 'new-password' });
    const section = panel('تغییر گذرواژه');
    const form = h('div', null,
        h('div', { class: 'notice' },
            'با تغییر گذرواژه، همه نشست‌های دیگر این حساب باطل می‌شوند. گذرواژه باید حداقل ۱۲ نویسه باشد و نام کاربری را در خود نداشته باشد.'),
        h('div', { class: 'form-grid', style: { marginTop: '14px' } },
            field('گذرواژه فعلی', current),
            field('گذرواژه جدید', next),
            field('تکرار گذرواژه جدید', repeat)
        ),
        h('div', { class: 'form-actions' },
            h('button', {
                class: 'btn primary', type: 'button',
                onclick: async () => {
                    if (next.value !== repeat.value) { toast('دو گذرواژه جدید یکسان نیستند.', 'warn'); return; }
                    try {
                        await api('/api/auth.php', {
                            method: 'POST',
                            body: { action: 'change_password', current_password: current.value, new_password: next.value },
                        });
                        current.value = '';
                        next.value = '';
                        repeat.value = '';
                        toast('گذرواژه تغییر کرد.');
                    } catch (error) { reportError(error); }
                },
            }, 'تغییر گذرواژه')
        )
    );
    section.querySelector('.panel-body').appendChild(form);
    root.appendChild(section);

    const info = panel('اطلاعات حساب');
    info.querySelector('.panel-body').appendChild(h('div', { class: 'kv' },
        h('dt', null, 'نام کاربری'), h('dd', { class: 'mono' }, state.user.username),
        h('dt', null, 'نقش'), h('dd', null, roleLabel(state.user.role)),
        h('dt', null, 'وضعیت'), h('dd', null, boolBadge(state.user.is_active)),
        h('dt', null, 'آخرین ورود'), h('dd', null, state.user.last_login_at ? fmtDate(state.user.last_login_at) : '—')
    ));
    root.appendChild(info);
}
