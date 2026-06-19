<?php

use App\Services\LanguageModerationService;

function moderationService(): LanguageModerationService
{
    $configuration = require dirname(__DIR__, 2) . '/config/language.php';

    return new LanguageModerationService($configuration['blocked_terms']);
}

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
