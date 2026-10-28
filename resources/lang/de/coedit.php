<?php

return [
    'someone' => 'Jemand',
    'empty' => '(leer)',
    'yes' => 'Ja',
    'no' => 'Nein',

    'status' => [
        'editing' => 'bearbeitet',
        'viewing' => 'sieht sich das an',
    ],

    'notice' => [
        'title' => ':name hat diesen Datensatz gespeichert',
        'fields' => 'Geändert: :fields ·',
        'hint' => 'Beim Speichern bleiben deren Änderungen erhalten. Du wählst nur bei Feldern, die ihr beide geändert habt.',
        'pull' => 'Änderungen übernehmen',
        'reload' => 'Neu laden',
        'dismiss' => 'Ausblenden',
    ],

    'pull' => [
        'title' => 'Formular aktualisiert',
        'taken' => 'Übernommen: :fields.',
        'conflicts' => 'Du hast :fields ebenfalls geändert; du wählst beim Speichern.',
    ],

    'taken' => [
        'title' => 'Änderungen von :name behalten',
        'body' => ':fields wurden geändert, während du bearbeitet hast. Dein Speichern hat sie nicht überschrieben.',
    ],

    'conflict' => [
        'heading' => ':name hat den Datensatz gespeichert, während du ihn bearbeitet hast',
        'description' => 'Ihr habt beide dieses Feld geändert. Wähle, welche Version gespeichert wird.|Ihr habt beide diese :count Felder geändert. Wähle für jedes die Version, die gespeichert wird.',
        'mine' => 'Meine behalten',
        'theirs' => 'Die von :name nehmen',
        'submit' => 'Speichern',
    ],

    'modal' => [
        'body' => 'Ihre Werte stehen unten. Sende erneut, um deine zu speichern, oder schließe, um ihre zu behalten.',
    ],

    'deleted' => ':name hat diesen Datensatz gelöscht, während du ihn bearbeitet hast. Nichts wurde gespeichert.',

    'busy' => 'Jemand anderes speichert diesen Datensatz gerade. Noch wurde nichts gespeichert: versuch es gleich noch einmal.',
];
