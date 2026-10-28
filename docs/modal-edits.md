# Modal edits

Relation managers, simple resources and any table with an `EditAction` edit records in a modal. Add `coEdit()` at the end of the chain:

```php
use Filament\Actions\EditAction;

->recordActions([
    EditAction::make()->coEdit(),
])
```

The modal gets the avatars and field markers, and its save merges. A modal has no room for a second dialog, so a conflict works in two steps: the first submit stops, and a notice shows their value for each field you both changed. Submit again to keep yours, or close the modal to keep theirs. The merge runs inside the save, after your `before()`, so `before()` and `after()` go anywhere in the chain (and your `before()` also runs on a submit that stops at a conflict). An `action()` replacing the save itself goes before `coEdit()`. Set `afterFormFilled()` before `coEdit()`, which wraps it: one set after replaces the fingerprint the merge needs, and the save then fails loudly (in production: is reported) instead of overwriting anyone unseen. `coEdit(ignore: ['views'])` leaves fields out of the merge, and `coEdit(presence: false)` keeps the merge without the avatars.

![Ana is in the same supplier's modal](images/modal.png)
