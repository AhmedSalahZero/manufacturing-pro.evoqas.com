<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — PreferenceController (language and theme)
//  Location: app/Http/Controllers/PreferenceController.php
//
//  POST /preferences  { language?: en|ar, theme?: light|dark }
//
//  A signed-in person's choice is saved on their account, so it follows them
//  to every device. It is ALSO kept in the session, so the sign-in page
//  (where nobody is signed in) remembers the choice too.
// ══════════════════════════════════════════════════════════════════

class PreferenceController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['sometimes', 'in:en,ar'],
            'theme'    => ['sometimes', 'in:light,dark'],
        ]);

        if ($user = $request->user()) {
            $user->forceFill($data)->save();
        }

        if (isset($data['language'])) {
            $request->session()->put('locale', $data['language']);
        }
        if (isset($data['theme'])) {
            $request->session()->put('theme', $data['theme']);
        }

        return back();
    }
}
