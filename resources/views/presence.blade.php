<div
    x-data="filamentCoEdit(@js($config))"
    x-on:visibilitychange.document="visibilityChanged()"
    x-on:focusin.document="focusChanged($event.target)"
    x-on:focusout.document="focusLeft()"
    x-on:livewire:navigating.window="leave()"
    x-on:pagehide.window="leave()"
    x-on:pageshow.window="$event.persisted && resume()"
    x-on:filament-coedit-seen.window="save = null"
    x-on:keydown.window="active()"
    x-on:pointerdown.window="active()"
    x-on:pointermove.window.throttle.5000ms="active()"
    x-on:wheel.window.passive.throttle.5000ms="active()"
    class="fi-coedit"
    role="status"
    aria-live="polite"
>
    <div class="fi-coedit-stack" x-show="members.length" x-cloak>
        <template x-for="member in visible" :key="member.user">
            <span
                class="fi-coedit-avatar"
                :class="{ 'fi-coedit-avatar-editing': member.mode === 'editing' }"
                :style="`--coedit-color: ${member.color}`"
                :title="describe(member)"
            >
                <img x-show="member.avatar" :src="member.avatar" :alt="member.name" />
                <span x-show="! member.avatar" x-text="initials(member.name)"></span>
            </span>
        </template>

        <span class="fi-coedit-avatar fi-coedit-more" x-show="hidden.length" x-text="`+${hidden.length}`" :title="hidden.map((member) => member.name).join(', ')"></span>

        <span class="fi-sr-only" x-text="members.map((member) => describe(member)).join('. ')"></span>
    </div>

    <template x-teleport="body">
        <div class="fi-coedit-notice" x-show="save" x-cloak x-transition.opacity.duration.200ms>
            <template x-if="save">
                <div class="fi-coedit-notice-inner">
                    <p class="fi-coedit-notice-title" x-text="save.self ? @js(__('filament-coedit::coedit.notice.self_title')) : @js(__('filament-coedit::coedit.notice.title', ['name' => '__NAME__'])).replace('__NAME__', save.name)"></p>
                    <p class="fi-coedit-notice-body">
                        <span x-show="save.fields.length" x-text="@js(__('filament-coedit::coedit.notice.fields', ['fields' => '__FIELDS__'])).replace('__FIELDS__', save.fields.join(', '))"></span>
                        <span x-text="ago(save.at)"></span>
                    </p>
                    @if ($config['mode'] === 'editing')
                        <p class="fi-coedit-notice-hint" x-show="! save.self">{{ __($config['context'] === 'modal' ? 'filament-coedit::coedit.notice.modal_hint' : 'filament-coedit::coedit.notice.hint') }}</p>
                        <p class="fi-coedit-notice-hint" x-show="save.self">{{ __('filament-coedit::coedit.notice.self_hint') }}</p>
                    @endif
                    <div class="fi-coedit-notice-actions">
                        @if ($config['mode'] === 'editing' && $config['context'] === 'modal')
                            {{-- A modal's form is filled again only by reopening it; saving merges as on a page. --}}
                        @elseif ($config['mode'] === 'editing')
                            <x-filament::button size="sm" x-on:click="pull()">
                                {{ __('filament-coedit::coedit.notice.pull') }}
                            </x-filament::button>
                        @else
                            <x-filament::button size="sm" x-on:click="window.location.reload()">
                                {{ __('filament-coedit::coedit.notice.reload') }}
                            </x-filament::button>
                        @endif
                        <x-filament::button size="sm" color="gray" x-on:click="dismiss()">
                            {{ __('filament-coedit::coedit.notice.dismiss') }}
                        </x-filament::button>
                    </div>
                </div>
            </template>
        </div>
    </template>
</div>

@script
<script>
    Alpine.data('filamentCoEdit', (config) => ({
        members: config.initial.members,
        save: config.initial.save,
        field: null,
        timer: null,
        stopped: false,
        cleanups: [],
        markers: [],
        skew: Math.floor(Date.now() / 1000) - config.now,
        clock: Date.now(),
        activity: Date.now(),
        idle: false,

        init() {
            // A modal autofocuses its first field before this component starts.
            if (document.activeElement && document.activeElement !== document.body) {
                this.focusChanged(document.activeElement)
            }

            this.schedule(0)
            this.$nextTick(() => this.mark())

            const clock = setInterval(() => (this.clock = Date.now()), 15000)
            this.cleanups.push(() => clearInterval(clock))

            // A page update morphs the form back to the server's markup; put the markers back on.
            const unhook = Livewire.hook('commit', ({ succeed }) => succeed(() => queueMicrotask(() => this.markers.length && this.mark())))
            typeof unhook === 'function' && this.cleanups.push(unhook)
        },

        // Gone for good (a modal closing, SPA navigation): nothing may report this tab again.
        destroy() {
            this.leave()
            this.unmark()
            this.cleanups.forEach((cleanup) => cleanup())
            this.cleanups = []
        },

        get visible() {
            return this.members.slice(0, 4)
        },

        get hidden() {
            return this.members.slice(4)
        },

        schedule(delay = config.heartbeat) {
            clearTimeout(this.timer)

            if (! this.stopped) {
                this.timer = setTimeout(() => this.beat(), delay)
            }
        },

        async beat() {
            if (document.hidden) {
                return this.schedule()
            }

            // Nobody at the page: stop reporting in, so the session can expire as it
            // would without Co-Edit. The first keypress or pointer move resumes.
            if (config.idle && Date.now() - this.activity > config.idle) {
                this.idle = true

                return
            }

            try {
                const snapshot = await this.$wire.heartbeat(this.field)

                // Left while this heartbeat was out: the server may have seen it after
                // the beacon, so leave once more and ignore the answer.
                if (this.stopped) {
                    return navigator.sendBeacon(config.leave)
                }

                this.members = snapshot.members
                this.save = snapshot.save
                this.mark()
            } catch (error) {
                // A dropped request is fine: the next heartbeat tries again.
            }

            this.schedule()
        },

        active() {
            this.activity = Date.now()

            if (this.idle) {
                this.idle = false
                this.schedule(0)
            }
        },

        visibilityChanged() {
            if (! document.hidden) {
                this.activity = Date.now()
                this.idle = false
                this.schedule(0)
            }
        },

        // A beacon survives the tab closing; a Livewire request might not.
        leave() {
            if (this.stopped) {
                return
            }

            this.stopped = true
            clearTimeout(this.timer)
            navigator.sendBeacon(config.leave) || this.$wire.leave()
        },

        // Back from the browser's back-forward cache after pagehide left the record.
        resume() {
            this.stopped = false
            this.activity = Date.now()
            this.idle = false
            this.schedule(0)
        },

        focusChanged(target) {
            if (config.mode !== 'editing' || ! config.trackFields) {
                return
            }

            const wrapper = target.closest?.('[data-field-wrapper]')
            const path = wrapper ? this.pathOf(wrapper) : null

            if (path && path !== this.field) {
                this.field = path
                this.schedule(120)
            }
        },

        focusLeft() {
            if (config.mode !== 'editing' || ! config.trackFields) {
                return
            }

            setTimeout(() => {
                if (! document.activeElement?.closest?.('[data-field-wrapper]') && this.field) {
                    this.field = null
                    this.schedule(300)
                }
            }, 150)
        },

        // A field's state path, from the first element inside it bound to Livewire:
        // `wire:model="data.title"`, or `$entangle('data.status')` for Alpine-driven fields.
        // Only fields of this component's own form: the page's, or the modal's.
        pathOf(wrapper) {
            for (const element of [wrapper, ...wrapper.querySelectorAll('*')]) {
                for (const attribute of element.attributes) {
                    const entangled = attribute.value.match(/\$entangle\(\s*['"]([^'"]+)['"]/)
                    const path = attribute.name.startsWith('wire:model') ? attribute.value : entangled?.[1]

                    if (path) {
                        return path.startsWith('mountedActions.') === (config.context === 'modal') ? path : null
                    }
                }
            }

            return null
        },

        mark() {
            this.unmark()

            const owners = new Map()

            for (const member of this.members) {
                for (const path of member.fields) {
                    owners.set(path, [...(owners.get(path) ?? []), member])
                }
            }

            if (! owners.size) {
                return
            }

            const wrappers = new Map()

            for (const wrapper of document.querySelectorAll('[data-field-wrapper]')) {
                const path = this.pathOf(wrapper)

                if (path) {
                    wrappers.set(path, wrapper)
                }
            }

            for (const [path, people] of owners) {
                const wrapper = wrappers.get(path)

                if (! wrapper) {
                    continue
                }

                const tag = document.createElement('span')
                tag.className = 'fi-coedit-tag'
                tag.textContent = people.map((person) => person.name).join(', ')
                tag.setAttribute('aria-hidden', 'true')

                wrapper.classList.add('fi-coedit-field')
                wrapper.style.setProperty('--coedit-color', people[0].color)
                wrapper.appendChild(tag)

                this.markers.push({ wrapper, tag })
            }
        },

        unmark() {
            for (const { wrapper, tag } of this.markers) {
                wrapper.classList.remove('fi-coedit-field')
                wrapper.style.removeProperty('--coedit-color')
                tag.remove()
            }

            this.markers = []
        },

        async pull() {
            await this.$wire.$parent.coeditPull()
            this.save = null
        },

        dismiss() {
            this.$wire.acknowledge(this.save?.version)
            this.save = null
        },

        describe(member) {
            const status = member.mode === 'editing'
                ? @js(__('filament-coedit::coedit.status.editing'))
                : @js(__('filament-coedit::coedit.status.viewing'))

            return `${member.name} · ${status}`
        },

        initials(name) {
            return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('')
        },

        ago(at) {
            this.clock
            const seconds = Math.max(0, Math.floor(Date.now() / 1000) - this.skew - at)
            const format = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, { numeric: 'auto' })

            if (seconds < 60) return format.format(-seconds, 'second')
            if (seconds < 3600) return format.format(-Math.floor(seconds / 60), 'minute')

            return format.format(-Math.floor(seconds / 3600), 'hour')
        },
    }))
</script>
@endscript
