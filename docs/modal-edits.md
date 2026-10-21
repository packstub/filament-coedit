# Modal edits

Relation managers, simple resources and any table with an `EditAction` edit records in a modal. Add `coEdit()` at the end of the chain:

```php
use Filament\Actions\EditAction;

->recordActions([
    EditAction::make()->coEdit(),
])
```

The modal gets the avatars and field markers, and its save merges. A modal has no room for a second dialog, so a conflict works in two steps: the first submit stops, and a notice shows their value for each field you both changed. Submit again to keep yours, or close the modal to keep theirs. Call `coEdit()` last, because it wraps the `afterFormFilled()`, `before()` and `after()` callbacks already set on the action. `coEdit(ignore: ['views'])` leaves fields out of the merge, and `coEdit(presence: false)` keeps the merge without the avatars.

![Ana is in the same supplier's modal](images/modal.png)
