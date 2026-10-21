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
        'fields' => 'Changed :fields ·',
        'hint' => 'Saving keeps their changes. You only choose for fields you both edited.',
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

    'deleted' => ':name deleted this record while you were editing. Nothing was saved.',
];
