<div
    x-data="filamentCoEdit(@js($config))"
    x-on:visibilitychange.document="visibilityChanged()"
    x-on:focusin.document="focusChanged($event.target)"
    x-on:focusout.document="focusLeft()"
    x-on:livewire:navigating.window="leave()"
    x-on:pagehide.window="leave()"
    x-on:pageshow.window="$event.persisted && resume()"
    x-on:filament-coedit-seen.window="show(null)"
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

</div>

@script
<script>
    Alpine.data('filamentCoEdit', (config) => ({
        members: config.initial.members,
        save: null,
        shown: null,
        field: null,
        timer: null,
        stopped: false,
        cleanups: [],
        markers: [],
        activity: Date.now(),
        idle: false,

        init() {
            // A modal autofocuses its first field before this component starts.
            if (document.activeElement && document.activeElement !== document.body) {
                this.focusChanged(document.activeElement)
            }

            this.schedule(0)
            this.$nextTick(() => {
                this.mark()
                this.show(config.initial.save)
            })

            // Closed from the toast itself (its ×, Escape, or one of its buttons): the person has seen it.
            const closed = (event) => this.closed(event.detail?.id)
            window.addEventListener('notificationClosed', closed)
            this.cleanups.push(() => window.removeEventListener('notificationClosed', closed))

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
                this.show(snapshot.save)
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

        // The "Ana saved this record" notice is a Filament notification, sent from here
        // because a heartbeat never renders. One id per room: a newer save replaces it.
        show(save) {
            this.save = save

            if (! save) {
                this.shown !== null && this.hide()

                return
            }

            if (save.version === this.shown || ! window.FilamentNotification) {
                return
            }

            this.shown = save.version

            const labels = config.labels
            const escape = (text) => text.replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`)
            const actions = []

            if (config.mode === 'editing' && config.context !== 'modal') {
                actions.push(new FilamentNotificationAction('pull').label(labels.pull).button().dispatch('coedit-pull', { room: config.room }).close())
            }

            if (config.mode !== 'editing') {
                actions.push(new FilamentNotificationAction('reload').label(labels.reload).button().url(window.location.href))
            }

            actions.push(new FilamentNotificationAction('dismiss').label(labels.dismiss).button().color('gray').close())

            const hint = config.mode !== 'editing' ? null
                : save.self ? labels.self_hint
                : config.context === 'modal' ? labels.modal_hint
                : labels.hint

            new FilamentNotification()
                .id(this.noticeId)
                .title(save.self ? labels.self_title : labels.title.replace(':name', escape(save.name)))
                .body([save.fields.length ? labels.fields.replace(':fields', escape(save.fields.join(', '))) : null, hint].filter(Boolean).join(' '))
                .icon('heroicon-o-arrow-path')
                .iconColor('primary')
                .persistent()
                .actions(actions)
                .send()
        },

        // The page merged the save, or the notice was dismissed elsewhere: nothing left to say.
        hide() {
            this.shown = null
            window.dispatchEvent(new CustomEvent('close-notification', { detail: { id: this.noticeId } }))
        },

        closed(id) {
            if (id !== this.noticeId) {
                return
            }

            this.shown = null

            if (this.save) {
                this.$wire.acknowledge(this.save.version)
                this.save = null
            }
        },

        get noticeId() {
            return `filament-coedit-save-${config.room}`
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
    }))
</script>
@endscript
