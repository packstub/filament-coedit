# How the merge works

When you save, Co-Edit reads the record from the database again and loads it through your form, the way the page did when it opened. It then compares three versions of every field: the form as you opened it, the form as you are saving it, and the record as it is now.

| You changed it | They changed it | Result |
| --- | --- | --- |
| no | no | unchanged |
| yes | no | yours is saved |
| no | yes | theirs is kept, and you're told |
| yes, the same way | yes | saved, no question |
| yes | yes, differently | the dialog asks you |

![The conflict dialog](https://raw.githubusercontent.com/packstub/art/main/filament-coedit/docs/conflict.png)

The comparison ignores differences that only come from how forms handle values:

- `"12"` and `12`, `10.00` and `10`
- `""`, `[]` and `null`
- `true` and `"1"`
- an enum and its value
- a date-time with or without the `T`, the seconds or a zero fraction; one with a zone is compared in the app's time zone
- the fresh UUID keys repeaters and file uploads get on every fill

Anything else is compared exactly. `"+40123"` and `"40123"` are different values, and so are two notes that start with the same date but go on differently.

Text that looks like a number is compared as text wherever Co-Edit can tell that it is text. The form as you opened it and the record as it is now both come from the database, so they compare exactly: a version field going from `1.1` to `1.10` is their change and is kept. Text you typed compares exactly too, so retyping `1.1` as `1.10` is your change, in a text field or in an item of a repeater, builder or key-value field. When you both changed a field, your value and theirs are compared as values, so your `10.5` against their `10.50` is the same change and no conflict.

Each form field is one unit, by its path. `meta.color` in a section with its own `statePath()` and `category.name` in a relationship group (`Group::make()->relationship()`) merge on their own, so two people changing two fields of one group never meet. A repeater, a builder, a key-value field or a JSON column edited by a single field merges as a whole. A rich editor merges as one field too. Opening it is no change, even though Filament's editor rewrites list items as it loads. Co-Edit knows the editor's own inline nodes; a custom inline node from your own editor extension is treated as a block in that rewrite, so opening a document with one inside a list item may count as your change.

The merge only covers fields on the form. Attributes the form doesn't edit, like timestamps, counters or columns a job updates, are never written by the merge and never reported.

## Good to know

- A heartbeat is one small Livewire request that doesn't re-render the page. With the default 4 seconds, ten people on a record send 2.5 heartbeats a second between them.
- A toggle never conflicts: it only has two values, so if they flipped it, you either left it alone (theirs is kept) or flipped it the same way.
- Relationship selects show related records by name in the dialog, even when their options are searched rather than preloaded.
- A tab in the background skips its heartbeats, so it drops off after the configured `timeout` seconds and comes back as soon as its user switches to it. A person with the record open in another, visible tab stays on. A page nobody has touched for the configured `idle` seconds stops too, so a forgotten tab neither keeps the session alive nor loads the server.
- After `idle` the session can expire as usual, so the first keypress or pointer move afterwards may meet Livewire's "page expired" prompt or your login page, as any request would.
- Saves of the same record run one at a time, behind a lock in the cache store. Two people pressing Save in the same instant are merged one after the other instead of overwriting each other. A save that waits more than 10 seconds for another one stops with a "try again" notice and writes nothing. Without a store that supports locks the saves are not serialised; Octane's store is one that does not. A save holds the lock for at most 30 seconds.
- To compare like with like, the record is filled through your page the same way it was loaded (`fillForm()`), so your `beforeFill()`/`afterFill()` hooks also run when a save merges and on **Get their changes**. The page's public properties are put back afterwards (objects as they are). Keep those hooks to setting page state: anything else they do (a query, a log line) happens on every merge. A modal's `mountUsing()` and `fillForm()` run again on each submit in the same way.
- Translatable fields (Filament's spatie-translatable plugin, `lara-zeus/spatie-translatable`) merge per locale on edit pages. The title in English and the title in Romanian are two fields, whichever locale each person has open. Ana's Romanian title stays when you save the page in English, and the dialog names the locale ("Title (RO)"). `getCoEditTranslatableAttributes()` says which attributes those are (the resource's, by default). A translatable attribute your form leaves out is saved as it is in the database now, never as your page loaded it. An ignored attribute (`getCoEditIgnoredFields()` takes the attribute, `body`, not `body@ro`) is left to plain Filament in every locale. A modal edit merges the locale it shows.
- Only Filament's own save merges. `saveFormComponentOnly()` (a field that saves its own section) writes as in plain Filament.
- If someone deletes the record while you are editing, your save never recreates it: Filament can no longer load the record and the request stops before anything is written.
- Names and avatars come from your panel (`getFilamentName()`, `getFilamentAvatarUrl()`), so people look the way they look everywhere else in the panel.
