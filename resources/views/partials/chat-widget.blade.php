{{--
    Staff chat widget — a single shared channel all logged-in roles can see
    and post to. Uses polling (not WebSockets/Reverb) since this app runs
    on plain XAMPP/shared hosting with no background process for a socket
    server — a few seconds of delay is a reasonable tradeoff for zero extra
    infrastructure. Styled after shadcn's chat "Bubble" pattern: rounded
    message bubbles, own messages in the primary color aligned right,
    others in a muted card aligned left, hover reveals a reaction popover,
    hovering the timestamp shows a tooltip with the full date/time.
--}}
<div x-data="staffChat()" x-init="init()" class="fixed bottom-5 right-5 z-40">

    {{-- Floating trigger bubble --}}
    <button @click="open = !open" title="Staff Chat"
        class="w-14 h-14 rounded-full bg-brand-600 hover:bg-brand-700 text-white shadow-lg flex items-center justify-center relative">
        <i data-lucide="message-circle" class="w-6 h-6" x-show="!open"></i>
        <i data-lucide="x" class="w-6 h-6" x-show="open" style="display:none;"></i>
        <span x-show="unread > 0 && !open" x-text="unread"
              class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-semibold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1"></span>
    </button>

    {{-- Chat panel --}}
    <div x-show="open"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
         style="display:none;"
         class="absolute bottom-[4.5rem] right-0 w-80 sm:w-96 h-[28rem] bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xl flex flex-col overflow-hidden">

        <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-2">
                <i data-lucide="message-circle" class="w-4 h-4 text-brand-600"></i>
                <p class="text-sm font-semibold text-ink dark:text-white">Staff Chat</p>
            </div>
            <button @click="open = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>

        <div id="chat-messages" class="flex-1 overflow-y-auto p-3 space-y-3">
            <template x-for="m in messages" :key="m.id">
                <div class="flex flex-col" :class="m.is_mine ? 'items-end' : 'items-start'" @mouseenter="hovering = m.id" @mouseleave="hovering = null">
                    <p class="text-[10px] text-slate-400 mb-0.5 px-1" x-show="!m.is_mine" x-text="m.user_name"></p>

                    <div class="relative max-w-[80%]">
                        <div class="rounded-xl px-3 py-2 text-sm"
                             :class="m.is_mine ? 'bg-brand-600 text-white rounded-br-sm' : 'bg-slate-100 dark:bg-slate-700 text-ink dark:text-white rounded-bl-sm'">
                            <span x-text="m.message" class="whitespace-pre-wrap break-words"></span>
                        </div>

                        {{-- Reaction trigger — appears on hover, opens a small emoji popover --}}
                        <div x-show="hovering === m.id" style="display:none;"
                             class="absolute -top-3 flex items-center gap-1" :class="m.is_mine ? 'left-0' : 'right-0'">
                            <div class="relative" x-data="{ pickerOpen: false }">
                                <button @click="pickerOpen = !pickerOpen" title="React"
                                    class="w-6 h-6 rounded-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 shadow-sm flex items-center justify-center text-xs hover:scale-110 transition-transform">
                                    🙂
                                </button>
                                {{-- Emoji popover --}}
                                <div x-show="pickerOpen" @click.outside="pickerOpen = false"
                                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-90" x-transition:enter-end="opacity-100 scale-100"
                                     style="display:none;"
                                     class="absolute bottom-full mb-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-full shadow-md px-2 py-1 flex gap-1 z-10"
                                     :class="m.is_mine ? 'right-0' : 'left-0'">
                                    <template x-for="emoji in ['👍','❤️','😂','🎉','😮']" :key="emoji">
                                        <button @click="react(m, emoji); pickerOpen = false" class="text-sm hover:scale-125 transition-transform" x-text="emoji"></button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Reaction pills --}}
                    <div class="flex flex-wrap gap-1 mt-1" x-show="Object.keys(m.reactions || {}).length > 0">
                        <template x-for="[emoji, userIds] in Object.entries(m.reactions || {})" :key="emoji">
                            <button @click="react(m, emoji)"
                                class="text-[11px] bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-full px-1.5 py-0.5 flex items-center gap-1">
                                <span x-text="emoji"></span><span x-text="userIds.length"></span>
                            </button>
                        </template>
                    </div>

                    {{-- Timestamp — tooltip shows the full date/time on hover --}}
                    <p class="text-[10px] text-slate-400 mt-0.5 px-1 cursor-default" :title="m.full_time" x-text="m.time"></p>
                </div>
            </template>
            <p x-show="messages.length === 0" class="text-center text-xs text-slate-400 mt-10">No messages yet — say hello 👋</p>
        </div>

        <form @submit.prevent="send()" class="p-3 border-t border-slate-100 dark:border-slate-700 flex gap-2 flex-shrink-0">
            <input type="text" x-model="draft" placeholder="Message the team..."
                   class="flex-1 text-sm rounded-full border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-4 py-2">
            <button type="submit" :disabled="!draft.trim()"
                class="w-9 h-9 rounded-full bg-brand-600 hover:bg-brand-700 disabled:opacity-40 text-white flex items-center justify-center flex-shrink-0">
                <i data-lucide="send" class="w-4 h-4"></i>
            </button>
        </form>
    </div>
</div>

<script>
function staffChat() {
    return {
        open: false,
        messages: [],
        draft: '',
        hovering: null,
        unread: 0,
        lastId: 0,
        historyLoaded: false,
        hasEverOpened: false,
        pollTimer: null,

        init() {
            // Nothing is fetched at all until the person opens the chat at
            // least once — most page loads across the whole site never
            // touch chat, so this avoids any background network/CPU work
            // for that common case (meaningful on older/low-power devices).
            this.$watch('open', (value) => {
                if (value) {
                    this.unread = 0;
                    this.hasEverOpened = true;
                    this.loadHistory().then(() => this.scrollToBottom());
                    this.schedulePoll(); // switch to the fast interval immediately
                }
            });

            // Pause entirely while the browser tab isn't visible — no point
            // polling a chat nobody's looking at, and it's one less timer
            // running in the background on the device.
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    clearTimeout(this.pollTimer);
                } else if (this.hasEverOpened) {
                    this.schedulePoll();
                }
            });
        },

        // Fast (3s) while the panel is open and visible; slower (20s, just
        // enough to keep the unread badge honest) while closed. Either way,
        // this is a single self-rescheduling timer, not setInterval, so the
        // delay can adapt to `open` without two timers ever running at once.
        schedulePoll() {
            clearTimeout(this.pollTimer);
            const delay = this.open ? 3000 : 20000;
            this.pollTimer = setTimeout(async () => {
                await this.poll();
                if (this.hasEverOpened && !document.hidden) this.schedulePoll();
            }, delay);
        },

        async loadHistory() {
            if (this.historyLoaded) return;
            const res = await fetch('{{ route("chat.history") }}');
            const data = await res.json();
            this.messages = data.messages;
            if (this.messages.length) this.lastId = this.messages[this.messages.length - 1].id;
            this.historyLoaded = true;
            this.$nextTick(() => this.scrollToBottom());
        },

        async poll() {
            if (!this.historyLoaded) { await this.loadHistory(); return; }
            const res = await fetch(`{{ route('chat.poll') }}?since=${this.lastId}`);
            const data = await res.json();
            if (data.messages.length) {
                // Only the message list re-renders (Alpine's x-for keys off
                // message id) — nothing else on the page is touched, so this
                // stays cheap even on older devices.
                this.messages.push(...data.messages);
                this.lastId = data.messages[data.messages.length - 1].id;
                if (!this.open) {
                    this.unread += data.messages.filter(m => !m.is_mine).length;
                } else {
                    this.$nextTick(() => this.scrollToBottom());
                }
            }
        },

        async send() {
            const text = this.draft.trim();
            if (!text) return;
            this.draft = '';
            const res = await fetch('{{ route("chat.store") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ message: text }),
            });
            const data = await res.json();
            this.messages.push(data.message);
            this.lastId = data.message.id;
            this.$nextTick(() => this.scrollToBottom());
        },

        async react(message, emoji) {
            const res = await fetch(`/chat/${message.id}/react`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ emoji }),
            });
            const data = await res.json();
            const target = this.messages.find(m => m.id === message.id);
            if (target) target.reactions = data.reactions;
        },

        scrollToBottom() {
            const el = document.getElementById('chat-messages');
            if (el) el.scrollTop = el.scrollHeight;
        },
    }
}
</script>
