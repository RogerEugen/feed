<?php

use App\Services\LanguageModerationService;

function moderationService(): LanguageModerationService
{
    $configuration = require dirname(__DIR__, 2) . '/config/language.php';

    return new LanguageModerationService(
        $configuration['blocked_terms'],
        $configuration['blocked_patterns']
    );
}

it('loads at least one thousand English and Kiswahili blocked variants', function () {
    $configuration = require dirname(__DIR__, 2) . '/config/language.php';

    expect(count($configuration['blocked_terms']))->toBeGreaterThanOrEqual(1000);
});

it('allows respectful feedback in English and Kiswahili', function () {
    $service = moderationService();

    expect($service->inspect(
        'The examination timetable changed without notice and students need clearer communication.'
    )['violates'])->toBeFalse();

    expect($service->inspect(
        'Ratiba ya mtihani ilibadilishwa bila taarifa na tunaomba maelekezo ya wazi.'
    )['violates'])->toBeFalse();
});

it('detects abusive English and Kiswahili language', function () {
    $service = moderationService();

    expect($service->inspect('You are a stupid fool and an idiot.')['violates'])->toBeTrue();
    expect($service->inspect('Wewe ni mpumbavu kabisa.')['violates'])->toBeTrue();
});

it('detects punctuation based evasion', function () {
    $service = moderationService();

    expect($service->inspect('You are an i.d.i.o.t.')['violates'])->toBeTrue();
});

it('detects leetspeak spacing and invisible character evasion', function () {
    $service = moderationService();

    expect($service->inspect('You are a f@cking 1d10t.')['violates'])->toBeTrue();
    expect($service->inspect('Wewe ni m.p.u.m.b.a.v.u kabisa.')['violates'])->toBeTrue();
    expect($service->inspect("mji\u{200B}nga wewe")['violates'])->toBeTrue();
});

it('detects flexible English and Kiswahili threats', function () {
    $service = moderationService();

    expect($service->inspect('I am going to hurt you tomorrow.')['violates'])->toBeTrue();
    expect($service->inspect('Nitakumaliza ukirudi hapa.')['violates'])->toBeTrue();
});
