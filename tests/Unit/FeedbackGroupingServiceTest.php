<?php

use App\Services\FeedbackGroupingService;

it('groups genuinely similar feedback and keeps unrelated issues separate', function () {
    $service = new FeedbackGroupingService();
    $base = [
        'tracking_code' => 'FB-1',
        'category_id' => 1,
        'category' => 'Facilities',
        'status' => 'submitted',
        'priority' => 'medium',
        'sender_role' => 'student',
        'recipient_department_id' => 3,
        'recipient_faculty_id' => 2,
        'submitted_at' => '2026-06-20 10:00:00',
        'resolved_at' => null,
        'resolution' => null,
    ];

    $groups = $service->group([
        [...$base, 'id' => 1, 'content' => 'The computer laboratory internet connection is very slow.'],
        [...$base, 'id' => 2, 'content' => 'Computer laboratory internet connection remains slow every morning.'],
        [...$base, 'id' => 3, 'content' => 'The library needs more current database textbooks.'],
    ]);

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['feedback_count'])->toBe(2)
        ->and($groups[1]['feedback_count'])->toBe(1);
});

it('uses the latest successful resolution as the group suggestion', function () {
    $service = new FeedbackGroupingService();
    $common = [
        'category_id' => 2,
        'category' => 'Academic',
        'priority' => 'medium',
        'sender_role' => 'student',
        'recipient_department_id' => 3,
        'recipient_faculty_id' => 2,
    ];

    $groups = $service->group([
        [...$common, 'id' => 1, 'tracking_code' => 'FB-1', 'content' => 'Lecture timetable changes are communicated late.', 'status' => 'submitted', 'submitted_at' => '2026-06-20', 'resolved_at' => null, 'resolution' => null],
        [...$common, 'id' => 2, 'tracking_code' => 'FB-2', 'content' => 'Lecture timetable changes were communicated late.', 'status' => 'resolved', 'submitted_at' => '2026-06-10', 'resolved_at' => '2026-06-12', 'resolution' => 'Publish timetable changes through the department notice channel.'],
    ]);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['suggested_solution'])->toBe('Publish timetable changes through the department notice channel.');
});
