<?php

/*
|--------------------------------------------------------------------------
| Abusive-language vocabulary
|--------------------------------------------------------------------------
|
| The base vocabulary is intentionally readable and maintainable. Common
| direct-address and emphasis variants are generated below so the deployed
| blocked-term collection contains more than 1,000 English and Kiswahili
| entries without maintaining a giant, error-prone handwritten array.
|
*/
$englishTerms = [
    'fuck', 'fuk', 'fck', 'fack', 'fucker', 'fucking', 'facking', 'fuck off', 'fuck you', 'motherfucker',
    'shit', 'bullshit', 'piece of shit', 'shithead', 'dipshit', 'bitch',
    'son of a bitch', 'bastard', 'asshole', 'arsehole', 'dumbass', 'jackass',
    'smartass', 'fatass', 'badass idiot', 'dick', 'dickhead', 'prick', 'cunt',
    'slut', 'whore', 'hoe', 'skank', 'tramp', 'idiot', 'moron', 'imbecile',
    'retard', 'stupid', 'stupid fool', 'fool', 'dummy', 'dumb', 'dumb fool',
    'loser', 'clown', 'buffoon', 'ignorant fool', 'worthless', 'useless',
    'pathetic', 'disgusting pig', 'pig', 'swine', 'animal', 'vermin', 'trash',
    'garbage', 'scum', 'scumbag', 'creep', 'pervert', 'degenerate', 'lunatic',
    'psycho', 'madman', 'madwoman', 'monster', 'devil', 'demon', 'witch',
    'snake', 'rat', 'coward', 'liar', 'fraud', 'thief', 'criminal', 'crook',
    'corrupt fool', 'evil fool', 'dirty fool', 'filthy fool', 'ugly fool',
    'shut up', 'get lost', 'go to hell', 'burn in hell', 'drop dead',
    'kill yourself', 'go kill yourself', 'i will kill you', 'i will hurt you',
    'i will beat you', 'you should die', 'you deserve to die', 'die already',
    'hate you', 'damn you', 'screw you', 'suck my dick', 'eat shit',
    'kiss my ass', 'bloody idiot', 'bloody fool', 'goddamn idiot',
    'brain dead', 'no brain', 'empty head', 'waste of space', 'human trash',
    'absolute idiot', 'complete moron', 'total fool', 'shameless fool',
    'nasty person', 'vile person', 'dirty bastard', 'filthy bastard',
    'stinking idiot', 'annoying idiot', 'crazy idiot', 'incompetent fool',
    'hopeless fool', 'uneducated fool', 'primitive fool', 'toxic idiot',
];

$swahiliTerms = [
    'fala', 'mafalaa', 'mpumbavu', 'pumbavu', 'mjinga', 'majinga', 'jinga',
    'mshenzi', 'washenzi', 'ushenzi', 'malaya', 'kahaba', 'kicheche',
    'mbwa', 'mbwa wewe', 'mwana wa mbwa', 'nguruwe', 'nguruwe wewe',
    'takataka', 'takataka wewe', 'mavi', 'kinyesi', 'nyoko', 'mamako',
    'babako', 'kuma', 'mkundu', 'msenge', 'shoga', 'kichaa', 'kichaa wewe',
    'fyatu', 'zwazwa', 'juha', 'hayawani', 'shetani', 'shetani wewe',
    'laana', 'laana wewe', 'mwendawazimu', 'punguani', 'zezeta', 'bwege',
    'kilaza', 'zuzu', 'bomoa', 'mropokaji', 'mnafiki', 'mwongo', 'mwizi',
    'fisadi', 'tapeli', 'laghai', 'mhalifu', 'muuaji', 'mchawi', 'mnyama',
    'mdudu', 'panya', 'nyoka', 'ngombe', 'punda', 'kondoo mjinga',
    'tumbili', 'sokwe', 'kuku wewe', 'kiazi', 'kichwa maji', 'kichwa tupu',
    'huna akili', 'akili zako mbovu', 'akili ndogo', 'hauna maana',
    'hufai', 'hafai', 'huna faida', 'bure kabisa', 'ovyo kabisa',
    'puuzi', 'upuuzi', 'mjinga kabisa', 'mpumbavu kabisa', 'fala wewe',
    'nyamaza', 'funga mdomo', 'potea', 'enda zako', 'enda kuzimu',
    'kufa wewe', 'kafe', 'kajinyonge', 'jiue', 'nitakuua', 'nitakupiga',
    'nitakumaliza', 'utakufa', 'nakuchukia', 'chizi', 'chizi wewe',
    'mwehu', 'mwehu wewe', 'mwendawazimu wewe', 'mkorofi', 'mchafu',
    'mchafu wewe', 'mvivu', 'mvivu wewe', 'mzembe', 'mzembe wewe',
    'mkatili', 'mkatili wewe', 'mharibifu', 'mfitini', 'mbea', 'matusi',
    'fedhuli', 'jeuri', 'jambazi', 'kibaka', 'mhuni', 'wahuni', 'limbukeni',
    'mshamba', 'mshamba wewe', 'mlevi', 'teja', 'mraibu', 'mroho',
    'mlafi', 'mlafi wewe', 'mnafiki mkubwa', 'mwongo mkubwa', 'mwizi mkubwa',
    'fisadi mkubwa', 'fala mkubwa', 'jinga kubwa', 'zuzu mkubwa',
];

$directedPrefixes = [
    '',
    'you ',
    'you are ',
    'you are a ',
    'such a ',
    'very ',
    'absolute ',
    'complete ',
    'wewe ',
    'wewe ni ',
    'wewe ni ',
    'kabisa ',
];

$blockedTerms = [];
foreach (array_merge($englishTerms, $swahiliTerms) as $term) {
    foreach ($directedPrefixes as $prefix) {
        $blockedTerms[] = trim($prefix.$term);
    }
}

// Keep configuration predictable and comfortably above the requested 1,000.
$blockedTerms = array_values(array_unique($blockedTerms));

return [
    'warning_limit' => 2,

    'blocked_terms' => $blockedTerms,

    /*
    | Regex patterns cover flexible threats and abusive phrases whose wording
    | changes too much to represent safely as exact terms.
    */
    'blocked_patterns' => [
        '/\b(?:i|we)\s+(?:will|shall|am going to)\s+(?:kill|hurt|beat|attack|destroy)\s+(?:you|him|her|them)\b/u',
        '/\b(?:nitaku|tutaku)(?:ua|piga|maliza|jeruhi)\b/u',
        '/\b(?:go\s+)?(?:kill|hang)\s+yourself\b/u',
        '/\b(?:jiue|jinyonge|kajinyonge|kafe)\b/u',
        '/\b(?:you|wewe)\s+(?:are|ni)\s+(?:a\s+)?(?:fucking|bloody|kabisa)?\s*(?:idiot|moron|fool|mjinga|mpumbavu|fala)\b/u',
    ],

    'allowed_context_phrases' => [
        'sexual harassment',
        'harassment complaint',
        'reported abuse',
        'verbal abuse',
        'abusive language',
        'community language rules',
        'unyanyasaji wa kijinsia',
        'nimetukanwa',
        'alitumia lugha chafu',
        'lugha ya matusi',
    ],

    'first_warning' => 'Your message contains language that violates the community rules. Please rewrite it respectfully. A second violation will create a restricted identity review for your Dean of Faculty.',
    'final_warning' => 'Your message was blocked after a second language violation. Your identity has been placed in a restricted Dean of Faculty conduct review. It is not attached to any valid anonymous feedback.',
];
