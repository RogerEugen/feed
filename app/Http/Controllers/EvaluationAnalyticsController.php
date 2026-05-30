<?php
namespace App\Http\Controllers;

use App\Models\CourseEvaluation;
use App\Models\EvaluationAnalytic;
use App\Models\EvaluationWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluationAnalyticsController extends Controller
{
    // ── System-wide analytics (Rector/Admin) ──────────────────
    public function systemOverview(Request $request): JsonResponse
    {
        $windowId = $request->query('window_id');

        // Get all windows if no specific one requested
        if (!$windowId) {
            $window   = EvaluationWindow::where('is_active', true)->first();
            $windowId = $window?->id;
        }

        if (!$windowId) {
            return response()->json([
                'success' => true,
                'overview' => $this->emptyOverview(),
            ]);
        }

        // Total evaluations submitted
        $totalEvaluations = CourseEvaluation::where('window_id', $windowId)->count();

        // Unique students (unique token hashes)
        $uniqueStudents = CourseEvaluation::where('window_id', $windowId)
            ->distinct('anonymous_token_hash')
            ->count('anonymous_token_hash');

        // Unique courses evaluated
        $uniqueCourses = CourseEvaluation::where('window_id', $windowId)
            ->distinct('course_code')
            ->count('course_code');

        // Unique departments
        $uniqueDepts = CourseEvaluation::where('window_id', $windowId)
            ->distinct('department_id')
            ->count('department_id');

        // System avg per criterion
        $avgTeaching    = round(CourseEvaluation::where('window_id', $windowId)->avg('teaching_quality') ?? 0, 2);
        $avgContent     = round(CourseEvaluation::where('window_id', $windowId)->avg('course_content') ?? 0, 2);
        $avgAssessment  = round(CourseEvaluation::where('window_id', $windowId)->avg('assessment_fairness') ?? 0, 2);
        $avgResources   = round(CourseEvaluation::where('window_id', $windowId)->avg('resources_available') ?? 0, 2);
        $avgAccess      = round(CourseEvaluation::where('window_id', $windowId)->avg('lecturer_accessibility') ?? 0, 2);
        $avgOverall     = round(CourseEvaluation::where('window_id', $windowId)->avg('overall_rating') ?? 0, 2);

        // Top 5 courses by avg overall
        $topCourses = EvaluationAnalytic::where('window_id', $windowId)
            ->where('results_visible', true)
            ->orderByDesc('avg_overall')
            ->limit(5)
            ->get()
            ->map(fn($a) => [
                'course_code'     => $a->course_code,
                'avg_overall'     => round($a->avg_overall, 2),
                'total_responses' => $a->total_responses,
            ]);

        // Bottom 5 courses needing attention
        $needsAttention = EvaluationAnalytic::where('window_id', $windowId)
            ->where('results_visible', true)
            ->orderBy('avg_overall')
            ->limit(5)
            ->get()
            ->map(fn($a) => [
                'course_code'     => $a->course_code,
                'avg_overall'     => round($a->avg_overall, 2),
                'total_responses' => $a->total_responses,
                'department_id'   => $a->department_id,
            ]);

        // Courses with results visible
        $coursesWithResults = EvaluationAnalytic::where('window_id', $windowId)
            ->where('results_visible', true)
            ->count();

        // Courses pending threshold
        $coursesPending = EvaluationAnalytic::where('window_id', $windowId)
            ->where('results_visible', false)
            ->count();

        return response()->json([
            'success' => true,
            'overview' => [
                'window_id'           => $windowId,
                'total_evaluations'   => $totalEvaluations,
                'unique_students'     => $uniqueStudents,
                'unique_courses'      => $uniqueCourses,
                'unique_departments'  => $uniqueDepts,
                'courses_with_results'=> $coursesWithResults,
                'courses_pending'     => $coursesPending,
                'system_averages'     => [
                    'teaching_quality'    => $avgTeaching,
                    'course_content'      => $avgContent,
                    'assessment_fairness' => $avgAssessment,
                    'resources'           => $avgResources,
                    'accessibility'       => $avgAccess,
                    'overall'             => $avgOverall,
                ],
                'top_courses'      => $topCourses,
                'needs_attention'  => $needsAttention,
            ],
        ]);
    }

    // ── Analytics by faculty (for rector comparing faculties) ─
    public function byFaculty(Request $request): JsonResponse
    {
        $windowId = $request->query('window_id');

        $faculties = EvaluationAnalytic::where('window_id', $windowId)
            ->where('results_visible', true)
            ->get()
            ->groupBy('faculty_id')
            ->map(function ($group, $facultyId) {
                return [
                    'faculty_id'      => $facultyId,
                    'total_courses'   => $group->count(),
                    'total_responses' => $group->sum('total_responses'),
                    'avg_overall'     => round($group->avg('avg_overall'), 2),
                    'avg_teaching'    => round($group->avg('avg_teaching_quality'), 2),
                    'avg_content'     => round($group->avg('avg_course_content'), 2),
                ];
            })
            ->values();

        return response()->json([
            'success'   => true,
            'faculties' => $faculties,
        ]);
    }

    // ── Trend data (evaluations per day for a window) ─────────
    public function trends(Request $request): JsonResponse
    {
        $windowId = $request->query('window_id');

        $trends = CourseEvaluation::where('window_id', $windowId)
            ->selectRaw('DATE(submitted_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($t) => [
                'date'  => $t->date,
                'count' => $t->count,
            ]);

        return response()->json([
            'success' => true,
            'trends'  => $trends,
        ]);
    }

    private function emptyOverview(): array
    {
        return [
            'total_evaluations'    => 0,
            'unique_students'      => 0,
            'unique_courses'       => 0,
            'unique_departments'   => 0,
            'courses_with_results' => 0,
            'courses_pending'      => 0,
            'system_averages'      => [
                'teaching_quality'    => 0,
                'course_content'      => 0,
                'assessment_fairness' => 0,
                'resources'           => 0,
                'accessibility'       => 0,
                'overall'             => 0,
            ],
            'top_courses'     => [],
            'needs_attention' => [],
        ];
    }
}