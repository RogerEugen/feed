<?php

return [
    'warning_limit' => 2,

    /*
    |--------------------------------------------------------------------------
    | Community language rules
    |--------------------------------------------------------------------------
    |
    | Matching is case-insensitive and also checks common punctuation/spacing
    | evasions. Keep entries lowercase. These rules block abusive language;
    | they never suppress a legitimate report merely because it describes
    | harassment, discrimination, or another sensitive issue.
    |
    */
    'blocked_terms' => [
        // English profanity and targeted abuse
        'fuck', 'fucker', 'fucking', 'motherfucker', 'shit', 'bullshit',
        'bitch', 'bastard', 'asshole', 'dumbass', 'jackass', 'dickhead',
        'prick', 'cunt', 'slut', 'whore', 'idiot', 'moron', 'imbecile',
        'retard', 'stupid fool', 'son of a bitch', 'piece of shit',
        'go to hell', 'kill yourself',

        // Kiswahili profanity and direct personal insults
        'fuck you', 'fala', 'mpumbavu', 'mjinga', 'mshenzi', 'malaya',
        'kahaba', 'pumbavu', 'shoga', 'kicheche', 'mbwa wewe', 'nguruwe',
        'takataka wewe', 'mavi', 'kinyesi', 'nyoko', 'mamako', 'babako',
        'kuma', 'mkundu', 'msenge', 'kichaa wewe', 'fyatu', 'zwazwa',
        'juha', 'hayawani', 'shetani wewe', 'laana wewe',
    ],

    'allowed_context_phrases' => [
        'sexual harassment',
        'harassment complaint',
        'reported abuse',
        'verbal abuse',
        'unyanyasaji wa kijinsia',
        'nimetukanwa',
        'alitumia lugha chafu',
    ],

    'first_warning' => 'Your message contains language that violates the community rules. Please rewrite it respectfully. A second violation will create a restricted identity review for your Dean of Faculty.',
    'final_warning' => 'Your message was blocked after a second language violation. Your identity has been placed in a restricted Dean of Faculty conduct review. It is not attached to any valid anonymous feedback.',
];
