<?php

namespace Packstub\CoEdit\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Presence\PresenceStore;

/**
 * The avatars in the page header. Renders once; after that the browser asks
 * for the room every few seconds (and when its user changes field) and draws
 * the answer itself, so a heartbeat never re-renders anything.
 */
class Presence extends Component
{
    /** Opaque (PresenceStore::opaque()): this is sent to the browser. */
    #[Locked]
    public string $room;

    #[Locked]
    public string $mode = 'viewing';

    /** Where the avatars sit: a page header, or a modal edit (`EditAction::coEdit()`). */
    #[Locked]
    public string $context = 'page';

    /** One per open tab, so two tabs of the same person are two heartbeats. */
    #[Locked]
    public string $tab;

    /** The last save this tab has seen; newer saves by others are announced. */
    #[Locked]
    public int $version = 0;

    public function mount(string $room, string $mode = 'viewing', int $version = 0, string $context = 'page'): void
    {
        $this->room = PresenceStore::opaque($room);
        $this->mode = $mode === 'editing' ? 'editing' : 'viewing';
        $this->context = $context === 'modal' ? 'modal' : 'page';
        $this->version = $version;
        $this->tab = Str::random(16);
    }

    /**
     * @return array{members: list<array<string, mixed>>, save: array<string, mixed>|null}
     */
    #[Renderless]
    public function heartbeat(mixed $field = null): array
    {
        $members = CoEdit::store()->touch($this->room, $this->tab, CoEdit::who(), $this->mode, $this->sanitizeField($field));

        return $this->snapshotWith($members);
    }

    #[Renderless]
    public function leave(): void
    {
        CoEdit::store()->leave($this->room, $this->tab);
    }

    /**
     * The person dismissed the notice, or their page merged the save.
     */
    #[Renderless]
    #[On('coedit-acknowledged')]
    public function acknowledge(?int $version = null, ?string $room = null): void
    {
        // The page's merge reaches every avatar stack on it; a modal's is another record.
        if ($room !== null && PresenceStore::opaque($room) !== $this->room) {
            return;
        }

        $latest = (int) (CoEdit::store()->lastSave($this->room)['version'] ?? 0);

        $this->version = max($this->version, min($version ?? $latest, $latest));
    }

    /**
     * @return array{members: list<array<string, mixed>>, save: array<string, mixed>|null}
     */
    #[Renderless]
    public function snapshot(): array
    {
        return $this->snapshotWith(CoEdit::store()->members($this->room, exceptUser: CoEdit::who()['user']));
    }

    /**
     * @param  list<array<string, mixed>>  $members  the others on the record
     * @return array{members: list<array<string, mixed>>, save: array<string, mixed>|null}
     */
    protected function snapshotWith(array $members): array
    {
        $me = CoEdit::who()['user'];
        $save = CoEdit::store()->lastSave($this->room);

        return [
            // Who is who stays on the server: the browser only tells people apart.
            'members' => array_map(fn (array $member): array => ['user' => PresenceStore::opaque($member['user'])] + $member, $members),
            'save' => $save !== null && $save['version'] > $this->version && $save['user'] !== $me
                ? ['version' => $save['version'], 'name' => $save['name'], 'at' => $save['at'], 'fields' => $save['fields']]
                : null,
        ];
    }

    public function render(): View
    {
        $members = CoEdit::store()->touch($this->room, $this->tab, CoEdit::who(), $this->mode);

        return view('filament-coedit::presence', [
            'config' => [
                'heartbeat' => max(1, (int) config('filament-coedit.heartbeat', 4)) * 1000,
                // null, 0 or false: never pause.
                'idle' => ($idle = (int) config('filament-coedit.idle', 600)) > 0 ? $idle * 1000 : null,
                'mode' => $this->mode,
                'context' => $this->context,
                'trackFields' => (bool) config('filament-coedit.track_fields', true),
                'initial' => $this->snapshotWith($members),
                'now' => now()->getTimestamp(),
                'leave' => static::leaveUrl($this->room, $this->tab),
            ],
        ]);
    }

    /**
     * Signed relative to the app (it survives proxies and host aliases), sent
     * with the app's base path so an app in a subdirectory receives it.
     */
    public static function leaveUrl(string $room, string $tab): string
    {
        return request()->getBaseUrl().URL::signedRoute('filament-coedit.leave', ['room' => $room, 'tab' => $tab], absolute: false);
    }

    /**
     * A state path from the browser ("data.title", "data.items.3f2a….name"), or nothing.
     * A page reports fields of its own form, a modal those of the mounted action: a
     * modal on the page edits another record, which the page's room must not show.
     */
    protected function sanitizeField(mixed $field): ?string
    {
        if (! is_string($field) || $this->mode !== 'editing' || ! config('filament-coedit.track_fields', true) || strlen($field) > 200) {
            return null;
        }

        if (str_starts_with($field, 'mountedActions.') !== ($this->context === 'modal')) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_\-.:]+$/', $field) ? $field : null;
    }
}
