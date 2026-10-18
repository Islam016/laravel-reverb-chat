<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>
</head>
<body class="h-screen bg-slate-100 text-slate-800">
<div class="flex h-full">
    {{-- Sidebar --}}
    <aside class="flex w-80 flex-col border-r bg-white">
        <div class="flex items-center justify-between border-b px-4 py-3">
            <div>
                <div class="font-semibold">{{ auth()->user()->name }}</div>
                <div id="connection" class="text-xs text-slate-400">connecting…</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-sm text-slate-500 hover:text-slate-800">Sign out</button></form>
        </div>
        <div class="space-y-2 border-b p-3">
            <select id="direct-user" class="w-full rounded border px-2 py-1 text-sm">
                <option value="">Start a direct message…</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
            <details class="text-sm">
                <summary class="cursor-pointer text-emerald-700">New group</summary>
                <form id="group-form" class="mt-2 space-y-2">
                    <input name="name" placeholder="Group name" required class="w-full rounded border px-2 py-1">
                    <select name="members" multiple size="5" class="w-full rounded border px-2 py-1">
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <button class="w-full rounded bg-emerald-600 py-1 text-white">Create group</button>
                </form>
            </details>
        </div>
        <ul id="conversations" class="flex-1 divide-y overflow-y-auto"></ul>
    </aside>

    {{-- Conversation --}}
    <main class="flex flex-1 flex-col">
        <header id="header" class="flex items-center justify-between border-b bg-white px-5 py-3">
            <span class="text-slate-400">Select a conversation</span>
        </header>
        <div id="messages" class="flex-1 space-y-2 overflow-y-auto p-5"></div>
        <form id="composer" class="hidden items-center gap-2 border-t bg-white p-3">
            <label class="cursor-pointer rounded px-2 py-1 text-xl text-slate-500 hover:bg-slate-100" title="Attach a file">📎<input type="file" name="attachment" class="hidden"></label>
            <span id="file-name" class="max-w-40 truncate text-xs text-slate-500"></span>
            <input name="body" autocomplete="off" placeholder="Type a message" class="flex-1 rounded-full border px-4 py-2">
            <button class="rounded-full bg-emerald-600 px-5 py-2 text-white">Send</button>
        </form>
    </main>
</div>

<script>
const me = @json(auth()->id());
const csrf = document.querySelector('meta[name=csrf-token]').content;
const state = { conversations: [], current: null, messages: [], channel: null };

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: @json(config('broadcasting.connections.reverb.key')),
    wsHost: @json(config('broadcasting.connections.reverb.options.host')),
    wsPort: @json((int) config('broadcasting.connections.reverb.options.port')),
    wssPort: @json((int) config('broadcasting.connections.reverb.options.port')),
    forceTLS: @json(config('broadcasting.connections.reverb.options.scheme') === 'https'),
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: { headers: { 'X-CSRF-TOKEN': csrf } },
});
Echo.connector.pusher.connection.bind('state_change', ({ current }) => {
    document.getElementById('connection').textContent = current === 'connected' ? '● online' : current;
});

async function api(url, options = {}) {
    const response = await fetch(url, {
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', ...(options.json ? { 'Content-Type': 'application/json' } : {}) },
        ...options,
        body: options.json ? JSON.stringify(options.json) : options.body,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) { alert(data.message || 'Something went wrong'); throw data; }
    return data;
}

const escape = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

async function loadConversations() {
    state.conversations = (await api('/conversations')).data;
    document.getElementById('conversations').innerHTML = state.conversations.map((c) => `
        <li><button data-id="${c.id}" class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-slate-50 ${state.current?.id === c.id ? 'bg-emerald-50' : ''}">
            <span class="min-w-0">
                <span class="block truncate font-medium">${c.type === 'group' ? '👥 ' : ''}${escape(c.title)}</span>
                <span class="block truncate text-xs text-slate-500">${escape(c.last_message?.body ?? 'No messages yet')}</span>
            </span>
            ${c.unread_count ? `<span class="ml-2 rounded-full bg-emerald-600 px-2 text-xs text-white">${c.unread_count}</span>` : ''}
        </button></li>`).join('');
}

function renderMessages() {
    const box = document.getElementById('messages');
    const others = state.current.participants.filter((p) => p.id !== me).length;
    box.innerHTML = state.messages.map((m) => {
        const mine = m.sender?.id === me;
        const ticks = mine ? (m.read_by.length >= others ? '<span class="text-sky-500">✓✓</span>' : (m.read_by.length ? '✓✓' : '✓')) : '';
        const file = m.attachment ? (m.attachment.is_image
            ? `<a href="${m.attachment.url}" target="_blank"><img src="${m.attachment.url}" class="mt-1 max-h-60 rounded"></a>`
            : `<a href="${m.attachment.url}" class="mt-1 block underline">📄 ${escape(m.attachment.name)}</a>`) : '';
        return `<div class="flex ${mine ? 'justify-end' : ''}">
            <div class="max-w-md rounded-2xl px-4 py-2 shadow-sm ${mine ? 'bg-emerald-100' : 'bg-white'}">
                ${state.current.type === 'group' && !mine ? `<div class="text-xs font-medium text-emerald-700">${escape(m.sender?.name)}</div>` : ''}
                ${m.body ? `<div class="whitespace-pre-wrap">${escape(m.body)}</div>` : ''}${file}
                <div class="mt-1 text-right text-[11px] text-slate-400">${new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} ${ticks}</div>
            </div></div>`;
    }).join('');
    box.scrollTop = box.scrollHeight;
}

async function open(id) {
    if (state.channel) Echo.leave(state.channel);
    const data = await api(`/conversations/${id}`);
    state.current = data.conversation;
    state.messages = data.messages;
    document.getElementById('header').innerHTML = `<span class="font-semibold">${escape(state.current.title)}</span>
        <span class="text-xs text-slate-500">${state.current.participants.map((p) => escape(p.name) + (p.role === 'admin' ? ' (admin)' : '')).join(', ')}</span>`;
    document.getElementById('composer').classList.replace('hidden', 'flex');
    renderMessages();

    state.channel = `conversations.${id}`;
    Echo.private(state.channel)
        .listen('.message.sent', (message) => {
            if (!state.messages.some((m) => m.id === message.id)) state.messages.push(message);
            renderMessages();
            if (message.sender?.id !== me) markRead();
        })
        .listen('.messages.read', ({ user_id, last_read_message_id }) => {
            state.messages.forEach((m) => { if (m.id <= last_read_message_id && !m.read_by.includes(user_id) && m.sender?.id !== user_id) m.read_by.push(user_id); });
            renderMessages();
        });

    await markRead();
}

const markRead = () => api(`/conversations/${state.current.id}/read`, { method: 'POST' }).then(loadConversations);

document.getElementById('conversations').addEventListener('click', (e) => {
    const button = e.target.closest('button[data-id]');
    if (button) open(Number(button.dataset.id));
});

document.getElementById('direct-user').addEventListener('change', async (e) => {
    if (!e.target.value) return;
    const { data } = await api('/conversations/direct', { method: 'POST', json: { user_id: Number(e.target.value) } });
    e.target.value = '';
    await loadConversations();
    open(data.id);
});

document.getElementById('group-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const members = [...form.members.selectedOptions].map((o) => Number(o.value));
    const { data } = await api('/conversations/groups', { method: 'POST', json: { name: form.name.value, members } });
    form.reset();
    await loadConversations();
    open(data.id);
});

const composer = document.getElementById('composer');
composer.attachment.addEventListener('change', () => {
    document.getElementById('file-name').textContent = composer.attachment.files[0]?.name ?? '';
});
composer.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!composer.body.value.trim() && !composer.attachment.files.length) return;
    const message = await api(`/conversations/${state.current.id}/messages`, { method: 'POST', body: new FormData(composer) });
    if (!state.messages.some((m) => m.id === message.data.id)) state.messages.push(message.data);
    composer.reset();
    document.getElementById('file-name').textContent = '';
    renderMessages();
    loadConversations();
});

Echo.private(`users.${me}`).listen('.conversation.changed', ({ conversation_id, change }) => {
    loadConversations();
    if (change === 'deleted' && state.current?.id === conversation_id) location.reload();
});

loadConversations();
</script>
</body>
</html>
