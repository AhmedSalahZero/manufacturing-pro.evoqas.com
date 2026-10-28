<?php

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Validation Strings (Arabic)
//  Location: lang/ar/validation.php
//
//  Without this file EVERY validation failure in the application
//  falls back to Laravel's English defaults — so an Arabic customer
//  filling an Arabic form is told "The password field must be at
//  least 8 characters." That was reported to us as "no message
//  appeared", which is a fair description of a message you cannot
//  read.
//
//  This has now regressed once. If it goes missing again the symptom
//  is silent: nothing errors, nothing logs, the app simply answers
//  half its users in the wrong language. tests/Unit/TranslationsTest
//  exists to catch that.
//
//  Only the rules this app actually uses are translated. Laravel
//  falls back to English for anything absent, so an untranslated
//  rule degrades rather than breaking.
// ══════════════════════════════════════════════════════════════════

return [

    'required'      => 'حقل :attribute مطلوب.',
    'required_if'   => 'حقل :attribute مطلوب في هذه الحالة.',
    'required_with' => 'حقل :attribute مطلوب.',
    'present'       => 'حقل :attribute مطلوب.',
    'confirmed'     => 'تأكيد :attribute غير مطابق.',
    'email'         => 'يرجى إدخال بريد إلكتروني صحيح.',
    'unique'        => 'هذا الـ:attribute مستخدم بالفعل.',
    'exists'        => 'الـ:attribute المختار غير صالح.',
    'integer'       => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'numeric'       => 'يجب أن يكون :attribute رقماً.',
    'date'          => 'يجب أن يكون :attribute تاريخاً صحيحاً.',
    'string'        => 'يجب أن يكون :attribute نصاً.',
    'array'         => 'يجب أن يكون :attribute قائمة.',
    'in'            => 'الـ:attribute المختار غير صالح.',
    'boolean'       => 'يجب أن يكون :attribute صح أو خطأ.',
    'current_password' => 'كلمة المرور الحالية غير صحيحة.',
    'digits'        => 'يجب أن يكون :attribute :digits أرقام.',
    'regex'         => 'صيغة :attribute غير صحيحة.',
    'decimal'       => 'يجب أن يكون :attribute رقمًا.',
    'gte'           => ['numeric' => 'يجب أن يكون :attribute :value أو أكثر.'],
    'required_unless' => 'حقل :attribute مطلوب.',

    'min' => [
        'numeric' => 'يجب ألا يقل :attribute عن :min.',
        'string'  => 'يجب ألا يقل :attribute عن :min حرفاً.',
        'array'   => 'يجب ألا يقل :attribute عن :min عنصراً.',
    ],

    'max' => [
        'numeric' => 'يجب ألا يزيد :attribute عن :max.',
        'string'  => 'يجب ألا يزيد :attribute عن :max حرفاً.',
        'array'   => 'يجب ألا يزيد :attribute عن :max عنصراً.',
    ],

    'after'           => 'يجب أن يكون :attribute بعد :date.',
    'after_or_equal'  => 'يجب أن يكون :attribute في :date أو بعده.',
    'before_or_equal' => 'يجب أن يكون :attribute في :date أو قبله.',

    // The password rules the project applies — see
    // AppServiceProvider (Password::defaults).
    'password' => [
        'letters'       => 'يجب أن تحتوي كلمة المرور على حرف واحد على الأقل.',
        'mixed'         => 'يجب أن تحتوي كلمة المرور على حرف كبير وحرف صغير.',
        'uppercase'     => 'يجب أن تحتوي كلمة المرور على حرف إنجليزي كبير واحد على الأقل (مثل A).',
        'numbers'       => 'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل.',
        'symbols'       => 'يجب أن تحتوي كلمة المرور على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت كلمة المرور هذه في تسريب بيانات. يرجى اختيار كلمة أخرى.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Field names
    |--------------------------------------------------------------------------
    |
    | Without these a message reads "حقل email مطلوب" — half
    | Arabic, half a database column name.
    |
    */

    'attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'token' => 'الرابط',
        'language' => 'اللغة',
        'theme' => 'المظهر',
    ],

];
