<?php

use App\Http\Controllers\CommunicationController;

function canJoinCommunicationRoom(string $role, int $actorId, string $room): bool
{
    $method = new ReflectionMethod(CommunicationController::class, 'roleCanJoin');

    return $method->invoke(app(CommunicationController::class), $role, $actorId, $room);
}

it('allows each HOD to access only their own Rector conversation', function () {
    expect(canJoinCommunicationRoom('hod', 17, 'leadership.hod.17'))->toBeTrue()
        ->and(canJoinCommunicationRoom('hod', 18, 'leadership.hod.17'))->toBeFalse()
        ->and(canJoinCommunicationRoom('dean', 17, 'leadership.hod.17'))->toBeFalse();
});

it('allows each Dean to access only their own Rector conversation', function () {
    expect(canJoinCommunicationRoom('dean', 11, 'leadership.dean.11'))->toBeTrue()
        ->and(canJoinCommunicationRoom('dean', 54, 'leadership.dean.11'))->toBeFalse()
        ->and(canJoinCommunicationRoom('hod', 11, 'leadership.dean.11'))->toBeFalse();
});

it('allows the Rector to access every isolated leadership conversation', function () {
    expect(canJoinCommunicationRoom('rector', 1, 'leadership.hod.17'))->toBeTrue()
        ->and(canJoinCommunicationRoom('rector', 1, 'leadership.dean.11'))->toBeTrue()
        ->and(canJoinCommunicationRoom('rector', 1, 'campus.leadership'))->toBeFalse();
});
