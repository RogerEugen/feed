<?php
namespace App\Http\Controllers;

use App\Models\CourseEvaluation;
use App\Models\EvaluationWindow;
use App\Models\EvaluationAnalytic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class CourseEvaluationController extends Controller
{
    // ── Check if student already evaluated a course ────────────
    public function checkSubmitted(Request $request): JsonResponse
    {
        $tokenHash = hash('sha256', $request->anonymous_token ?? '');

        $exists = CourseEvaluation::where('anonymous_token_hash', $tokenHash)
            ->where('course_code', $request->course_code)
            ->exists();

        return response()->json([
            'success'   => true,
            'submitted' => $exists,
        ]);
    }

    // ── Submit course evaluation ───────────────────────────────
   public function submit(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'anonymous_token'        => ['required', 'string', 'min:10'],
        'window_id'              => ['required', 'integer', 'exists:evaluation_windows,id'],
        'course_code'            => ['required', 'string', 'max:20'],
        'subject_name'           => ['required', 'string', 'max:150'],
        'lecturer_id'            => ['required', 'integer'],
        'lecturer_name'          => ['required', 'string', 'max:150'],
        'department_id'          => ['required', 'integer'],
        'faculty_id'             => ['required', 'integer'],
        'academic_year'          => ['required', 'string'],
        'semester'               => ['required', 'integer', 'in:1,2'],
        'teaching_quality'       => ['required', 'integer', 'min:1', 'max:5'],
        'course_content'         => ['required', 'integer', 'min:1', 'max:5'],
        'assessment_fairness'    => ['required', 'integer', 'min:1', 'max:5'],
        'resources_available'    => ['required', 'integer', 'min:1', 'max:5'],
        'lecturer_accessibility' => ['required', 'integer', 'min:1', 'max:5'],
        'overall_rating'         => ['required', 'integer', 'min:1', 'max:5'],
        'comments'               => ['nullable', 'string', 'max:2000'],
    ]);

    if ($validator->fails()) {
        Log::error('Evaluation validation failed', $validator->errors()->toArray());
        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors'  => $validator->errors(),
        ], 422);
    }

    // Check window is open
    $window = EvaluationWindow::find($request->window_id);
    if (!$window) {
        return response()->json([
            'success' => false,
            'message' => 'Evaluation window not found.',
        ], 404);
    }

    // ✅ For evaluation we do NOT use is_used check
    // We validate the token exists and is not expired/revoked
    // but we DON'T mark it as used (evaluations are not one-time submissions)
    $authCheck = $this->validateAnonTokenForEvaluation($request->anonymous_token);
    if (!$authCheck['valid']) {
        return response()->json([
            'success' => false,
            'message' => $authCheck['message'],
        ], 401);
    }

    $tokenHash  = hash('sha256', $request->anonymous_token);
    $courseCode = strtoupper(trim($request->course_code));

    // Check duplicate — same token hash + course + window
    $exists = CourseEvaluation::where('anonymous_token_hash', $tokenHash)
        ->where('course_code', $courseCode)
        ->where('window_id', $request->window_id)
        ->exists();

    if ($exists) {
        return response()->json([
            'success' => false,
            'message' => 'You have already evaluated this course in this window.',
        ], 422);
    }

    // Encrypt comments
    $encryptedComments = null;
    $iv                = null;

    if ($request->filled('comments')) {
        $iv                = bin2hex(random_bytes(8));
        $encryptedComments = $this->encryptContent($request->comments, $iv);
    }

    $evaluation = CourseEvaluation::create([
        'anonymous_token_hash'   => $tokenHash,
        'window_id'              => $request->window_id,
        'course_code'            => $courseCode,
        'subject_name'           => $request->subject_name,
        'lecturer_id'            => $request->lecturer_id,
        'lecturer_name'          => $request->lecturer_name,
        'department_id'          => $request->department_id,
        'faculty_id'             => $request->faculty_id,
        'academic_year'          => $request->academic_year,
        'semester'               => $request->semester,
        'teaching_quality'       => $request->teaching_quality,
        'course_content'         => $request->course_content,
        'assessment_fairness'    => $request->assessment_fairness,
        'resources_available'    => $request->resources_available,
        'lecturer_accessibility' => $request->lecturer_accessibility,
        'overall_rating'         => $request->overall_rating,
        'encrypted_comments'     => $encryptedComments,
        'encryption_iv'          => $iv,
        'submitted_at'           => now(),
    ]);

    Log::info('Evaluation saved', ['id' => $evaluation->id, 'course' => $courseCode]);

    $this->recomputeAnalytics(
        $request->window_id,
        $courseCode,
        $request->department_id,
        $request->faculty_id
    );

    return response()->json([
        'success' => true,
        'message' => 'Course evaluation submitted successfully.',
    ], 201);
}

//  Separate validation for evaluations — does NOT mark token as used
private function validateAnonTokenForEvaluation(string $plainToken): array
{
    try {
        $response = Http::timeout(5)
            ->post(
                config('services.auth_service.url') . '/api/token/validate-evaluation',
                ['anonymous_token' => $plainToken]
            );

        if ($response->successful()) {
            return ['valid' => true];
        }

        return [
            'valid'   => false,
            'message' => $response->json('message', 'Invalid token.'),
        ];
    } catch (\Exception $e) {
        return ['valid' => false, 'message' => 'Auth service unavailable.'];
    }
}

    // ── Get department results (HOD view) ──────────────────────
    public function departmentResults(Request $request): JsonResponse
    {
        $departmentId = $request->query('department_id');
        $windowId     = $request->query('window_id');

        $analytics = EvaluationAnalytic::where('department_id', $departmentId)
            ->when($windowId, fn($q) => $q->where('window_id', $windowId))
            ->where('results_visible', true)
            ->with('window')
            ->orderByDesc('avg_overall')
            ->get();

        // For each analytic, get the most recent subject_name and lecturer_name
        // from the actual evaluations (since analytics table doesn't store these)
        return response()->json([
            'success'   => true,
            'analytics' => $analytics->map(function ($a) {
                // Get first evaluation for this course to get names
                $sample = \App\Models\CourseEvaluation::where('course_code', $a->course_code)
                    ->where('window_id', $a->window_id)
                    ->where('department_id', $a->department_id)
                    ->first(['subject_name', 'lecturer_name']);

                return [
                    'course_code'             => $a->course_code,
                    'subject_name'            => $sample?->subject_name,
                    'lecturer_name'           => $sample?->lecturer_name,
                    'window'                  => $a->window?->title,
                    'total_responses'         => $a->total_responses,
                    'avg_teaching_quality'    => round($a->avg_teaching_quality, 2),
                    'avg_course_content'      => round($a->avg_course_content, 2),
                    'avg_assessment_fairness' => round($a->avg_assessment_fairness, 2),
                    'avg_resources'           => round($a->avg_resources, 2),
                    'avg_accessibility'       => round($a->avg_accessibility, 2),
                    'avg_overall'             => round($a->avg_overall, 2),
                    'dept_avg_overall'        => round($a->dept_avg_overall, 2),
                ];
            }),
        ]);
    }
    // ── Get faculty results (Dean view) ───────────────────────
    public function facultyResults(Request $request): JsonResponse
    {
        $facultyId = $request->query('faculty_id');
        $windowId  = $request->query('window_id');

        $analytics = EvaluationAnalytic::where('faculty_id', $facultyId)
            ->when($windowId, fn($q) => $q->where('window_id', $windowId))
            ->where('results_visible', true)
            ->with('window')
            ->orderByDesc('avg_overall')
            ->get();

        return response()->json([
            'success'   => true,
            'analytics' => $analytics->map(fn($a) => [
                'course_code'         => $a->course_code,
                'department_id'       => $a->department_id,
                'window'              => $a->window?->title,
                'total_responses'     => $a->total_responses,
                'avg_overall'         => round($a->avg_overall, 2),
                'dept_avg_overall'    => round($a->dept_avg_overall, 2),
                'faculty_avg_overall' => round($a->faculty_avg_overall, 2),
            ]),
        ]);
    }

    // ── Recompute analytics for a course ──────────────────────
    private function recomputeAnalytics(
        int $windowId,
        string $courseCode,
        int $departmentId,
        int $facultyId
    ): void {
        $evaluations = CourseEvaluation::where('window_id', $windowId)
            ->where('course_code', $courseCode)
            ->where('department_id', $departmentId)
            ->get();

        $count = $evaluations->count();

        if ($count === 0) return;

        $avg = fn(string $col) => round($evaluations->avg($col), 4);

        // Dept average overall (all courses in dept for this window)
        $deptAvg = CourseEvaluation::where('window_id', $windowId)
            ->where('department_id', $departmentId)
            ->avg('overall_rating') ?? 0;

        // Faculty average overall
        $facultyAvg = CourseEvaluation::where('window_id', $windowId)
            ->where('faculty_id', $facultyId)
            ->avg('overall_rating') ?? 0;

        EvaluationAnalytic::updateOrCreate(
            [
                'window_id'     => $windowId,
                'course_code'   => $courseCode,
                'department_id' => $departmentId,
            ],
            [
                'faculty_id'              => $facultyId,
                'total_responses'         => $count,
                'avg_teaching_quality'    => $avg('teaching_quality'),
                'avg_course_content'      => $avg('course_content'),
                'avg_assessment_fairness' => $avg('assessment_fairness'),
                'avg_resources'           => $avg('resources_available'),
                'avg_accessibility'       => $avg('lecturer_accessibility'),
                'avg_overall'             => $avg('overall_rating'),
                'dept_avg_overall'        => round($deptAvg, 4),
                'faculty_avg_overall'     => round($facultyAvg, 4),
                'results_visible'         => $count >= 5, // threshold
                'computed_at'             => now(),
            ]
        );
    }

    private function validateAnonToken(string $plainToken): array
    {
        try {
            $response = Http::timeout(5)
                ->post(
                    config('services.auth_service.url') . '/api/token/validate',
                    ['anonymous_token' => $plainToken]
                );

            return $response->successful()
                ? ['valid' => true]
                : ['valid' => false, 'message' => $response->json('message', 'Invalid token.')];
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => 'Auth service unavailable.'];
        }
    }

    private function encryptContent(string $content, string $iv): string
    {
        $key = config('app.feedback_encryption_key', config('app.key'));
        return base64_encode(
            openssl_encrypt($content, 'AES-256-CBC', substr($key, 0, 32), 0, substr($iv, 0, 16))
        );
    }

    // Proxy to Auth Service to get lecturers by department
    public function getLecturers(int $departmentId): JsonResponse
    {
        try {
            $response = Http::timeout(5)
                ->get(
                    config('services.auth_service.url') . "/api/departments/{$departmentId}/lecturers"
                );

            if ($response->successful()) {
                return response()->json([
                    'success'   => true,
                    'lecturers' => $response->json('lecturers', []),
                ]);
            }

            return response()->json(['success' => true, 'lecturers' => []]);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'lecturers' => []]);
        }
    }

    // ── Get results for a specific lecturer only ───────────────────
public function lecturerResults(Request $request): JsonResponse
{
    $departmentId = $request->query('department_id');
    $lecturerId   = $request->query('lecturer_id');
    $windowId     = $request->query('window_id');

    if (!$lecturerId) {
        return response()->json([
            'success'   => true,
            'analytics' => [],
            'message'   => 'No lecturer ID provided.',
        ]);
    }

    //  Get all course codes that this specific lecturer taught
    // by looking at what students actually submitted evaluations for
    $lecturerCourseCodes = CourseEvaluation::where('lecturer_id', $lecturerId)
        ->where('window_id', $windowId)
        ->when($departmentId, fn($q) => $q->where('department_id', $departmentId))
        ->pluck('course_code')
        ->unique()
        ->values()
        ->toArray();

    if (empty($lecturerCourseCodes)) {
        return response()->json([
            'success'   => true,
            'analytics' => [],
        ]);
    }

    //  Get analytics only for courses taught by this lecturer
    $analytics = EvaluationAnalytic::whereIn('course_code', $lecturerCourseCodes)
        ->when($windowId, fn($q) => $q->where('window_id', $windowId))
        ->when($departmentId, fn($q) => $q->where('department_id', $departmentId))
        ->where('results_visible', true)
        ->with('window')
        ->orderByDesc('avg_overall')
        ->get();

    return response()->json([
        'success'   => true,
        'analytics' => $analytics->map(function ($a) use ($lecturerId) {

            // Get subject name from this specific lecturer's evaluations
            $sample = CourseEvaluation::where('course_code', $a->course_code)
                ->where('window_id', $a->window_id)
                ->where('department_id', $a->department_id)
                ->where('lecturer_id', $lecturerId)
                ->first(['subject_name', 'lecturer_name']);

            // Per-lecturer averages for THIS course (not all lecturers)
            $lecturerEvals = CourseEvaluation::where('course_code', $a->course_code)
                ->where('window_id', $a->window_id)
                ->where('department_id', $a->department_id)
                ->where('lecturer_id', $lecturerId)
                ->get();

            $count = $lecturerEvals->count();

            // Compute averages specific to this lecturer
            $avgTeaching    = $count ? round($lecturerEvals->avg('teaching_quality'), 2) : 0;
            $avgContent     = $count ? round($lecturerEvals->avg('course_content'), 2) : 0;
            $avgAssessment  = $count ? round($lecturerEvals->avg('assessment_fairness'), 2) : 0;
            $avgResources   = $count ? round($lecturerEvals->avg('resources_available'), 2) : 0;
            $avgAccessibility = $count ? round($lecturerEvals->avg('lecturer_accessibility'), 2) : 0;
            $avgOverall     = $count ? round($lecturerEvals->avg('overall_rating'), 2) : 0;

            return [
                'course_code'             => $a->course_code,
                'subject_name'            => $sample?->subject_name,
                'lecturer_name'           => $sample?->lecturer_name,
                'window'                  => $a->window?->title,
                'total_responses'         => $count,
                'avg_teaching_quality'    => $avgTeaching,
                'avg_course_content'      => $avgContent,
                'avg_assessment_fairness' => $avgAssessment,
                'avg_resources'           => $avgResources,
                'avg_accessibility'       => $avgAccessibility,
                'avg_overall'             => $avgOverall,
                'dept_avg_overall'        => round($a->dept_avg_overall, 2),
            ];
        })->filter(fn($a) => $a['total_responses'] >= 5)->values(), // ✅ enforce threshold per lecturer
    ]);
}
}