@livewire(\Packstub\CoEdit\Livewire\Presence::class, ['room' => $room, 'mode' => $mode, 'version' => $version], key('filament-coedit-'.($context ?? 'page').'-'.sha1($room)))
