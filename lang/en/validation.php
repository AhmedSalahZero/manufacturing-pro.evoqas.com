<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Validation Strings (English)
//  Location: lang/en/validation.php
//
//  Deliberately thin: Laravel ships its own complete English messages and
//  merges them with this file. This file only adds human field names in
//  `attributes`, so a message reads "The e-mail field is required" and
//  not "The email field is required". Keep attributes in step with
//  lang/ar/validation.php (tests/Unit/TranslationsTest.php checks it).
// ══════════════════════════════════════════════════════════════════

return [

    'password' => [
        'uppercase' => 'The password must contain at least one capital letter (e.g. A).',
    ],

    'attributes' => [
        'name' => 'name',
        'email' => 'e-mail',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'token' => 'link',
        'language' => 'language',
        'theme' => 'theme',
    ],

];
