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

it('never merges a repeated issue from different departments', function () {
    $service = new FeedbackGroupingService();
    $base = [
        'category_id' => 2,
        'category' => 'Academic',
        'status' => 'submitted',
        'priority' => 'medium',
        'sender_role' => 'student',
        'recipient_faculty_id' => 5,
        'submitted_at' => '2026-06-21',
        'resolved_at' => null,
        'resolution' => null,
    ];

    $groups = $service->group([
        [...$base, 'id' => 1, 'tracking_code' => 'FB-1', 'recipient_department_id' => 3, 'content' => 'The lecturer does not attend class.'],
        [...$base, 'id' => 2, 'tracking_code' => 'FB-2', 'recipient_department_id' => 6, 'content' => 'The lecturer does not attend class.'],
    ]);

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['department_id'])->not->toBe($groups[1]['department_id']);
});

it('recognises English and Kiswahili lecturer attendance complaints as one issue', function () {
    $service = new FeedbackGroupingService();
    $base = [
        'category_id' => 2,
        'category' => 'Academic',
        'status' => 'submitted',
        'priority' => 'high',
        'sender_role' => 'student',
        'recipient_department_id' => 3,
        'recipient_faculty_id' => 5,
        'submitted_at' => '2026-06-21',
        'resolved_at' => null,
        'resolution' => null,
    ];

    $groups = $service->group([
        [...$base, 'id' => 1, 'tracking_code' => 'FB-1', 'content' => 'The lecturer does not attend class regularly.'],
        [...$base, 'id' => 2, 'tracking_code' => 'FB-2', 'content' => 'Mwalimu haingii darasani mara kwa mara.'],
        [...$base, 'id' => 3, 'tracking_code' => 'FB-3', 'content' => 'Teacher absence from lectures is affecting our learning.'],
    ]);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['feedback_count'])->toBe(3)
        ->and($groups[0]['department_id'])->toBe(3)
        ->and($groups[0]['investigation_level'])->toBeIn(['high', 'critical']);
});
