<?php

return [
    'someone' => 'Someone',
    'empty' => '(empty)',
    'yes' => 'Yes',
    'no' => 'No',

    'status' => [
        'editing' => 'editing',
        'viewing' => 'viewing',
    ],

    'notice' => [
        'title' => ':name saved this record',
        'self_title' => 'You saved this record in another tab',
        'fields' => 'Changed :fields ·',
        'hint' => 'Saving keeps their changes. You only choose for fields you both edited.',
        'self_hint' => 'Saving here keeps what you saved there, except fields you changed here too.',
        'modal_hint' => 'Saving keeps their changes. For a field you both edited you see their value first; save again to keep yours.',
        'pull' => 'Get their changes',
        'reload' => 'Reload',
        'dismiss' => 'Dismiss',
    ],

    'pull' => [
        'title' => 'Form updated',
        'taken' => 'Brought in: :fields.',
        'conflicts' => 'You also changed :fields; you will choose when you save.',
    ],

    'taken' => [
        'title' => 'Kept :name\'s changes',
        'body' => ':fields changed while you were editing. Your save left them as they are.',
    ],

    'conflict' => [
        'heading' => ':name saved this record while you were editing',
        'description' => 'You both changed this field. Choose which version to save.|You both changed these :count fields. Choose which version to save for each.',
        'mine' => 'Keep mine',
        'theirs' => 'Use :name\'s',
        'submit' => 'Save',
    ],

    'modal' => [
        'body' => 'Their values are below. Submit again to save yours, or close to keep theirs.',
    ],

    'deleted' => ':name deleted this record while you were editing. Nothing was saved.',

    'busy' => 'Someone else is saving this record. Nothing was saved yet: try again in a moment.',
];
