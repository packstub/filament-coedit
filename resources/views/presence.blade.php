<div
    x-data="filamentCoEdit(@js($config))"
    x-on:visibilitychange.document="visibilityChanged()"
    x-on:focusin.document="focusChanged($event.target)"
    x-on:focusout.document="focusLeft()"
    x-on:livewire:navigating.window="leave()"
    x-on:pagehide.window="leave()"
    x-on:filament-coedit-seen.window="save = null"
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
                    <p class="fi-coedit-notice-title" x-text="@js(__('filament-coedit::coedit.notice.title', ['name' => '__NAME__'])).replace('__NAME__', save.name)"></p>
                    <p class="fi-coedit-notice-body">
                        <span x-show="save.fields.length" x-text="@js(__('filament-coedit::coedit.notice.fields', ['fields' => '__FIELDS__'])).replace('__FIELDS__', save.fields.join(', '))"></span>
                        <span x-text="ago(save.at)"></span>
                    </p>
                    @if ($config['mode'] === 'editing')
                        <p class="fi-coedit-notice-hint">{{ __('filament-coedit::coedit.notice.hint') }}</p>
                    @endif
                    <div class="fi-coedit-notice-actions">
                        @if ($config['mode'] === 'editing')
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
        markers: [],
        skew: Math.floor(Date.now() / 1000) - config.now,
        clock: Date.now(),

        init() {
            this.schedule(0)
            this.$nextTick(() => this.mark())
            setInterval(() => (this.clock = Date.now()), 15000)

            // A page update morphs the form back to the server's markup; put the markers back on.
            Livewire.hook('commit', ({ succeed }) => succeed(() => queueMicrotask(() => this.markers.length && this.mark())))
        },

        destroy() {
            clearTimeout(this.timer)
            this.unmark()
        },

        get visible() {
            return this.members.slice(0, 4)
        },

        get hidden() {
            return this.members.slice(4)
        },

        schedule(delay = config.heartbeat) {
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.beat(), delay)
        },

        async beat() {
            if (document.hidden) {
                return this.schedule()
            }

            try {
                const snapshot = await this.$wire.heartbeat(this.field)
                this.members = snapshot.members
                this.save = snapshot.save
                this.mark()
            } catch (error) {
                // A dropped request is fine: the next heartbeat tries again.
            }

            this.schedule()
        },

        visibilityChanged() {
            if (! document.hidden) {
                this.schedule(0)
            }
        },

        // A beacon survives the tab closing; a Livewire request might not.
        leave() {
            clearTimeout(this.timer)
            navigator.sendBeacon(config.leave) || this.$wire.leave()
        },

        focusChanged(target) {
            if (config.mode !== 'editing') {
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
            if (config.mode !== 'editing') {
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
        pathOf(wrapper) {
            for (const element of [wrapper, ...wrapper.querySelectorAll('*')]) {
                for (const attribute of element.attributes) {
                    if (attribute.name.startsWith('wire:model')) {
                        return attribute.value
                    }

                    const entangled = attribute.value.match(/\$entangle\(\s*['"]([^'"]+)['"]/)

                    if (entangled) {
                        return entangled[1]
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
