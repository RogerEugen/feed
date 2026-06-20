<?php

use App\Http\Controllers\CommunicationController;
use App\Models\CommunicationMessage;
use App\Models\CommunicationReadState;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $this->markTestSkipped('pdo_sqlite is not installed in this environment.');
    }
});

it('counts only incoming messages after the reader last opened the room', function () {
    DB::beginTransaction();

    try {
        $room = 'leadership.hod.99101';
        CommunicationMessage::create([
            'room' => $room,
            'sender_role' => 'rector',
            'encrypted_message' => 'test',
            'encryption_iv' => 'test',
            'sent_at' => now(),
        ]);
        CommunicationMessage::create([
            'room' => $room,
            'sender_role' => 'hod',
            'encrypted_message' => 'test',
            'encryption_iv' => 'test',
            'sent_at' => now(),
        ]);

        $controller = app(CommunicationController::class);
        $unread = new ReflectionMethod($controller, 'unreadCount');
        $markRead = new ReflectionMethod($controller, 'markRoomRead');

        expect($unread->invoke($controller, $room, 'hod', 99101))->toBe(1);

        $markRead->invoke($controller, $room, 'hod', 99101);

        expect($unread->invoke($controller, $room, 'hod', 99101))->toBe(0)
            ->and(CommunicationReadState::where([
                'room' => $room,
                'actor_role' => 'hod',
                'actor_id' => 99101,
            ])->exists())->toBeTrue();
    } finally {
        DB::rollBack();
    }
});

it('keeps unread state separate for every participant', function () {
    DB::beginTransaction();

    try {
        $room = 'leadership.dean.99102';
        CommunicationMessage::create([
            'room' => $room,
            'sender_role' => 'dean',
            'encrypted_message' => 'test',
            'encryption_iv' => 'test',
            'sent_at' => now(),
        ]);

        $controller = app(CommunicationController::class);
        $unread = new ReflectionMethod($controller, 'unreadCount');
        $markRead = new ReflectionMethod($controller, 'markRoomRead');

        expect($unread->invoke($controller, $room, 'rector', 1))->toBe(1);
        $markRead->invoke($controller, $room, 'rector', 1);

        expect($unread->invoke($controller, $room, 'rector', 1))->toBe(0)
            ->and($unread->invoke($controller, $room, 'rector', 2))->toBe(1);
    } finally {
        DB::rollBack();
    }
});
