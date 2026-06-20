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
        $validator = Validator::make($request->all(), [
            'anonymous_token' => ['required', 'string', 'min:10'],
            'window_id' => ['required', 'integer'],
            'course_code' => ['required', 'string'],
            'lecturer_id' => ['required', 'integer'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $authCheck = $this->validateAnonTokenForEvaluation($request->anonymous_token);
        $tokenHash = hash('sha256', $request->anonymous_token);
        $participantHash = $authCheck['participant_key'] ?? null;

        $exists = CourseEvaluation::where(function ($query) use ($participantHash, $tokenHash) {
                $participantHash
                    ? $query->where('participant_hash', $participantHash)
                    : $query->where('anonymous_token_hash', $tokenHash);
            })
            ->where('window_id', $request->window_id)
            ->where('course_code', strtoupper(trim($request->course_code)))
            ->where('lecturer_id', $request->lecturer_id)
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

    // Window, academic year and semester are authoritative. Student input may
    // never move an evaluation into a different period.
    $window = EvaluationWindow::find($request->window_id);
    if (!$window) {
        return response()->json([
            'success' => false,
            'message' => 'Evaluation window not found.',
        ], 404);
    }

    if (
        !$window->is_active
        || now()->lt($window->opens_at)
        || now()->gt($window->closes_at)
    ) {
        return response()->json([
            'success' => false,
            'message' => 'This evaluation window is not currently open.',
        ], 422);
    }

    if (
        $request->academic_year !== $window->academic_year
        || (int) $request->semester !== (int) $window->semester
    ) {
        return response()->json([
            'success' => false,
            'message' => 'The academic year or semester does not match the active evaluation window.',
        ], 422);
    }

    // ✅ For evaluation we do NOT use is_used check
    // We validate the token exists and is not expired/revoked
    // but we DON'T mark it as used (evaluations are not one-time submissions)
    $authCheck = $this->validateAnonTokenForEvaluation($request->anonymous_token);
    if (!$authCheck['valid'] || ($authCheck['role'] ?? null) !== 'student') {
        return response()->json([
            'success' => false,
            'message' => $authCheck['message'] ?? 'Only students can submit course evaluations.',
        ], 401);
    }

    if (
        !empty($authCheck['department_id'])
        && (int) $authCheck['department_id'] !== (int) $request->department_id
    ) {
        return response()->json([
            'success' => false,
            'message' => 'You can only evaluate lecturers in your own department.',
        ], 403);
    }

    if (!$this->lecturerBelongsToDepartment((int) $request->lecturer_id, (int) $request->department_id)) {
        return response()->json([
            'success' => false,
            'message' => 'The selected lecturer does not belong to your department.',
        ], 422);
    }

    $tokenHash  = hash('sha256', $request->anonymous_token);
    $courseCode = strtoupper(trim($request->course_code));

    $participantHash = $authCheck['participant_key'] ?? null;

    // Stable participant hash survives token refreshes without exposing identity.
    $exists = CourseEvaluation::where(function ($query) use ($participantHash, $tokenHash) {
            $participantHash
                ? $query->where('participant_hash', $participantHash)
                : $query->where('anonymous_token_hash', $tokenHash);
        })
        ->where('course_code', $courseCode)
        ->where('lecturer_id', $request->lecturer_id)
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
        'participant_hash'       => $participantHash,
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
            return ['valid' => true, ...$response->json()];
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
        $validator = Validator::make($request->all(), [
            'department_id' => ['required', 'integer', 'min:1'],
            'window_id' => ['required', 'integer', 'exists:evaluation_windows,id'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $departmentId = $request->query('department_id');
        $windowId     = $request->query('window_id');

        return response()->json([
            'success'   => true,
            'analytics' => $this->groupedResults($windowId, (int) $departmentId, null, null),
        ]);
    }
    // ── Get faculty results (Dean view) ───────────────────────
    public function facultyResults(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'faculty_id' => ['required', 'integer', 'min:1'],
            'window_id' => ['required', 'integer', 'exists:evaluation_windows,id'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $facultyId = $request->query('faculty_id');
        $windowId  = $request->query('window_id');

        return response()->json([
            'success'   => true,
            'analytics' => $this->groupedResults($windowId, null, (int) $facultyId, null),
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

    private function lecturerBelongsToDepartment(int $lecturerId, int $departmentId): bool
    {
        try {
            $response = Http::timeout(5)->get(
                config('services.auth_service.url') . "/api/departments/{$departmentId}/lecturers"
            );

            return $response->successful()
                && collect($response->json('lecturers', []))
                    ->contains(fn($lecturer) => (int) ($lecturer['id'] ?? 0) === $lecturerId);
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Get results for a specific lecturer only ───────────────────
public function lecturerResults(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'department_id' => ['nullable', 'integer', 'min:1'],
        'lecturer_id' => ['required', 'integer', 'min:1'],
        'window_id' => ['required', 'integer', 'exists:evaluation_windows,id'],
    ]);
    if ($validator->fails()) {
        return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
    }

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

    return response()->json([
        'success'   => true,
        'analytics' => $this->groupedResults(
            $windowId,
            $departmentId ? (int) $departmentId : null,
            null,
            (int) $lecturerId
        ),
    ]);
}

private function groupedResults(?int $windowId, ?int $departmentId, ?int $facultyId, ?int $lecturerId)
{
    if (!$windowId) {
        return collect();
    }

    $query = CourseEvaluation::query()
        ->where('window_id', $windowId)
        ->when($departmentId, fn($q) => $q->where('department_id', $departmentId))
        ->when($facultyId, fn($q) => $q->where('faculty_id', $facultyId))
        ->when($lecturerId, fn($q) => $q->where('lecturer_id', $lecturerId));

    $groups = $query->get()->groupBy(
        fn(CourseEvaluation $evaluation) => implode('|', [
            $evaluation->course_code,
            $evaluation->lecturer_id,
            $evaluation->department_id,
        ])
    );

    $window = EvaluationWindow::find($windowId);
    $deptAverages = CourseEvaluation::where('window_id', $windowId)
        ->get()
        ->groupBy('department_id')
        ->map(fn($items) => round($items->avg('overall_rating'), 2));
    $facultyAverages = CourseEvaluation::where('window_id', $windowId)
        ->get()
        ->groupBy('faculty_id')
        ->map(fn($items) => round($items->avg('overall_rating'), 2));

    return $groups
        ->filter(fn($items) => $items->count() >= 5)
        ->map(function ($items) use ($window, $deptAverages, $facultyAverages) {
            $sample = $items->first();

            return [
                'course_code' => $sample->course_code,
                'subject_name' => $sample->subject_name,
                'lecturer_id' => $sample->lecturer_id,
                'lecturer_name' => $sample->lecturer_name,
                'department_id' => $sample->department_id,
                'faculty_id' => $sample->faculty_id,
                'window' => $window?->title,
                'total_responses' => $items->count(),
                'avg_teaching_quality' => round($items->avg('teaching_quality'), 2),
                'avg_course_content' => round($items->avg('course_content'), 2),
                'avg_assessment_fairness' => round($items->avg('assessment_fairness'), 2),
                'avg_resources' => round($items->avg('resources_available'), 2),
                'avg_accessibility' => round($items->avg('lecturer_accessibility'), 2),
                'avg_overall' => round($items->avg('overall_rating'), 2),
                'dept_avg_overall' => $deptAverages[$sample->department_id] ?? 0,
                'faculty_avg_overall' => $facultyAverages[$sample->faculty_id] ?? 0,
            ];
        })
        ->sortByDesc('avg_overall')
        ->values();
}
}
