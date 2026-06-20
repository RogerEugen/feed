<?php
namespace App\Http\Controllers;

use App\Events\FeedbackMessageSent;
use App\Models\Feedback;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackCategory;
use App\Models\FeedbackResponse;
use App\Services\LanguageModerationService;
use App\Services\FeedbackGroupingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FeedbackController extends Controller
{
    // ─────────────────────────────────────────────
    // GET CATEGORIES (for form dropdown)
    // ─────────────────────────────────────────────
    public function categories(Request $request): JsonResponse
    {
        $role = $request->query('role', 'any');
        $includeInactive = filter_var($request->query('include_inactive', false), FILTER_VALIDATE_BOOLEAN);

        $categories = FeedbackCategory::query()
            ->when(!$includeInactive, fn($q) => $q->where('is_active', true))
            ->when(!in_array($role, ['all', 'admin'], true), function ($q) use ($role) {
                $q->where(function ($inner) use ($role) {
                    $inner->where('sender_role', $role)
                        ->orWhere('sender_role', 'any');
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'routes_to', 'sender_role', 'description', 'is_active']);

        return response()->json([
            'success'    => true,
            'categories' => $categories,
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
            'routes_to' => ['required', 'in:hod,dean,rector,admin'],
            'sender_role' => ['required', 'in:student,lecturer,any'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $slug = Str::slug($request->slug ?: $request->name);
        if (FeedbackCategory::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::lower(Str::random(4));
        }

        $category = FeedbackCategory::create([
            'name' => $request->name,
            'slug' => $slug,
            'routes_to' => $request->routes_to,
            'sender_role' => $request->sender_role,
            'description' => $request->description,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['success' => true, 'category' => $category], 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $category = FeedbackCategory::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
            'routes_to' => ['sometimes', 'required', 'in:hod,dean,rector,admin'],
            'sender_role' => ['sometimes', 'required', 'in:student,lecturer,any'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        if ($request->filled('slug') || $request->filled('name')) {
            $slug = Str::slug($request->slug ?: $request->name ?: $category->name);
            if (FeedbackCategory::where('slug', $slug)->where('id', '!=', $category->id)->exists()) {
                $slug .= '-' . Str::lower(Str::random(4));
            }
            $category->slug = $slug;
        }

        $category->fill($request->only(['name', 'routes_to', 'sender_role', 'description']));
        if ($request->has('is_active')) {
            $category->is_active = (bool) $request->is_active;
        }
        $category->save();

        return response()->json(['success' => true, 'category' => $category]);
    }

    public function deleteCategory(int $id): JsonResponse
    {
        $category = FeedbackCategory::findOrFail($id);
        if ($category->feedbacks()->exists()) {
            $category->update(['is_active' => false]);
            return response()->json([
                'success' => true,
                'message' => 'Category has feedback history, so it was deactivated instead of deleted.',
            ]);
        }
        $category->delete();
        return response()->json(['success' => true, 'message' => 'Category deleted successfully.']);
    }


    // ─────────────────────────────────────────────
    // SUBMIT FEEDBACK
    // ─────────────────────────────────────────────
    public function submit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'anonymous_token'      => ['required', 'string', 'min:10'],
            'category_id'          => ['required', 'integer', 'exists:feedback_categories,id'],
            'content'              => ['required', 'string', 'min:10', 'max:5000'],
            'priority'             => ['sometimes', 'in:low,medium,high,urgent'],
            'sender_role'          => ['required', 'string', 'in:student,lecturer'],
            'sender_department_id' => ['nullable', 'integer'],
            'recipient_faculty_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $moderation = app(LanguageModerationService::class)->inspect($request->content);
        if ($moderation['violates']) {
            return $this->rejectUnsafeContent(
                $request->anonymous_token,
                $request->content
            );
        }

        // ── ROLE ROUTING ENFORCEMENT ──────────────────────────────
        $category = FeedbackCategory::findOrFail($request->category_id);

        $allowedRoutes = match ($request->sender_role) {
            'student'  => ['hod', 'dean', 'admin'],
            'lecturer' => ['rector', 'admin'],
            default    => [],
        };

        if (!in_array($category->routes_to, $allowedRoutes)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to submit feedback to ' . $category->routes_to . ' as a ' . $request->sender_role . '.',
            ], 403);
        }

        // validate token
        $tokenHash = hash('sha256', $request->anonymous_token);
        $authCheck = $this->validateAnonToken($request->anonymous_token);

        if (!$authCheck['valid']) {
            return response()->json([
                'success' => false,
                'message' => $authCheck['message'],
            ], 401);
        }

        // encrypt and store
        $iv               = bin2hex(random_bytes(8));
        $encryptedContent = $this->encryptContent($request->content, $iv);

        $feedback = Feedback::create([
            'tracking_code'           => Feedback::generateTrackingCode(),
            'anonymous_token_hash'    => $tokenHash,
            'sender_role'             => $request->sender_role,
            'sender_department_id'    => $request->sender_department_id,
            'category_id'             => $category->id,
            'routed_to'               => $category->routes_to,
            'recipient_department_id' => $request->sender_department_id,
            'recipient_faculty_id'    => $request->recipient_faculty_id,
            'encrypted_content'       => $encryptedContent,
            'encryption_iv'           => $iv,
            'has_attachment'          => false,
            'priority'                => $request->priority ?? 'medium',
            'status'                  => 'submitted',
            'submitted_at'            => now(),
        ]);

        return response()->json([
            'success'       => true,
            'message'       => 'Feedback submitted successfully.',
            'tracking_code' => $feedback->tracking_code,
            'routed_to'     => $feedback->routed_to,
            'status'        => $feedback->status,
        ], 201);
    }

    // ─────────────────────────────────────────────
    // HOD — list feedbacks for their department
    // ─────────────────────────────────────────────
    public function hodList(Request $request): JsonResponse
    {
        $departmentId = $request->query('department_id');

        $feedbacks = Feedback::where('routed_to', 'hod')
            ->where('recipient_department_id', $departmentId)
            ->whereNotIn('status', ['closed'])
            ->with('category')
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn($f) => $this->formatFeedback($f));

        return response()->json([
            'success'   => true,
            'feedbacks' => $feedbacks,
        ]);
    }

    // ─────────────────────────────────────────────
    // DEAN — list escalated feedbacks for their faculty
public function deanList(Request $request): JsonResponse
{
    $facultyId = $request->query('faculty_id');

    if (!$facultyId) {
        return response()->json(['success' => true, 'feedbacks' => []]);
    }

    $feedbacks = Feedback::where('routed_to', 'dean')
        ->where('recipient_faculty_id', (int) $facultyId)
        ->with('category')
        ->orderByDesc('submitted_at')
        ->get()
        ->map(fn($f) => $this->formatFeedback($f));

    return response()->json([
        'success'   => true,
        'feedbacks' => $feedbacks,
    ]);
}
    // ─────────────────────────────────────────────
    // RECTOR — all feedbacks campus-wide
    // ─────────────────────────────────────────────
    public function rectorList(Request $request): JsonResponse
    {
        $departmentId = $request->query('department_id');

        $query = Feedback::with('category')
            ->orderByDesc('submitted_at');

        if ($departmentId) {
            $query->where('recipient_department_id', (int) $departmentId);
        }

        $feedbacks = $query->get()
            ->map(fn($f) => $this->formatFeedback($f));

        return response()->json([
            'success'   => true,
            'feedbacks' => $feedbacks,
        ]);
    }

    // ─────────────────────────────────────────────
    // SHOW single feedback (decrypted)
    // ─────────────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $feedback = Feedback::with(['category', 'responses', 'attachments', 'followups'])
            ->findOrFail($id);

        $decrypted = $this->decryptContent(
            $feedback->encrypted_content,
            $feedback->encryption_iv
        );

        return response()->json([
            'success'  => true,
            'feedback' => [
                ...$this->formatFeedback($feedback),
                'content'   => $decrypted,
                'responses' => $feedback->responses->map(fn($r) => [
                    'id'             => $r->id,
                    'responder_role' => $r->responder_role,
                    'content'        => $this->decryptContent($r->encrypted_response, $r->encryption_iv),
                    'is_escalation'  => $r->is_escalation_note,
                    'responded_at'   => $r->responded_at,
                ]),
                'followups' => $feedback->followups->map(fn($f) => [
                    'direction' => $f->direction,
                    'content'    => $this->decryptContent($f->encrypted_message, $f->encryption_iv),
                    'sent_at'   => $f->sent_at,
                ]),
                'realtime_channel' => $this->realtimeChannel($feedback->tracking_code),
            ],
        ]);
    }

    // ─────────────────────────────────────────────
    // RESPOND to feedback
    // ─────────────────────────────────────────────
    public function respond(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'response'        => ['required', 'string', 'min:5'],
            'responder_role'  => ['required', 'in:hod,dean,rector,admin'],
            'department_id'   => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $feedback = Feedback::findOrFail($id);

        $iv        = bin2hex(random_bytes(8));
        $encrypted = $this->encryptContent($request->response, $iv);

        $feedback->responses()->create([
            'responder_role'          => $request->responder_role,
            'responder_department_id' => $request->department_id,
            'encrypted_response'      => $encrypted,
            'encryption_iv'           => $iv,
            'is_escalation_note'      => false,
            'responded_at'            => now(),
        ]);

        $feedback->update(['status' => 'under_review']);

        broadcast(new FeedbackMessageSent(
            $this->realtimeChannel($feedback->tracking_code),
            [
                'type' => 'response',
                'responder_role' => $request->responder_role,
                'content' => $request->response,
                'sent_at' => now()->toIso8601String(),
            ]
        ));

        return response()->json([
            'success' => true,
            'message' => 'Response submitted successfully.',
        ]);
    }

    // ─────────────────────────────────────────────
    // ESCALATE feedback
    // ─────────────────────────────────────────────
    public function escalate(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'escalate_to'    => ['required', 'in:dean,rector,admin'],
            'responder_role' => ['required', 'in:hod,dean'],
            'note'           => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $feedback = Feedback::findOrFail($id);

        // Add escalation note as a response
        $note = $request->note ?? 'Escalated to ' . $request->escalate_to;
        $iv   = bin2hex(random_bytes(8));

        $feedback->responses()->create([
            'responder_role'          => $request->responder_role,
            'responder_department_id' => null,
            'encrypted_response'      => $this->encryptContent($note, $iv),
            'encryption_iv'           => $iv,
            'is_escalation_note'      => true,
            'responded_at'            => now(),
        ]);

        $feedback->update([
            'status'       => 'escalated',
            'routed_to'    => $request->escalate_to,
            'is_escalated' => true,
            'escalated_to' => $request->escalate_to,
            'escalated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Feedback escalated to ' . $request->escalate_to . ' successfully.',
        ]);
    }

    // ─────────────────────────────────────────────
    // RESOLVE feedback
    // ─────────────────────────────────────────────
    public function resolve(Request $request, int $id): JsonResponse
    {
        $feedback = Feedback::findOrFail($id);
        $resolution = trim((string) $request->input('resolution', ''));

        if ($resolution !== '') {
            $iv = bin2hex(random_bytes(8));
            $feedback->responses()->create([
                'responder_role'          => $request->input('responder_role', 'admin'),
                'responder_department_id' => null,
                'encrypted_response'      => $this->encryptContent($resolution, $iv),
                'encryption_iv'           => $iv,
                'is_escalation_note'      => false,
                'responded_at'            => now(),
            ]);
        }

        $feedback->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);

        broadcast(new FeedbackMessageSent(
            $this->realtimeChannel($feedback->tracking_code),
            [
                'type' => 'resolved',
                'status' => 'resolved',
                'resolved_at' => now()->toIso8601String(),
            ]
        ));

        return response()->json([
            'success' => true,
            'message' => 'Feedback marked as resolved.',
        ]);
    }

    public function suggestResolutions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'content' => ['required', 'string', 'min:10', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:feedback_categories,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $keywords = $this->extractKeywords($request->content);
        $limit = (int) ($request->input('limit', 3));

        $resolved = Feedback::with(['category', 'responses'])
            ->where('status', 'resolved')
            ->when($request->filled('category_id'), fn($q) => $q->where('category_id', (int) $request->category_id))
            ->orderByDesc('resolved_at')
            ->limit(150)
            ->get();

        $suggestions = $resolved->map(function (Feedback $feedback) use ($keywords) {
            $content = $this->decryptContent($feedback->encrypted_content, $feedback->encryption_iv);
            $score = $this->keywordSimilarity($keywords, $this->extractKeywords($content));
            $latestResponse = $feedback->responses->sortByDesc('responded_at')->first();
            $resolution = $latestResponse
                ? $this->decryptContent($latestResponse->encrypted_response, $latestResponse->encryption_iv)
                : null;

            return [
                'feedback_id' => $feedback->id,
                'tracking_code' => $feedback->tracking_code,
                'category' => $feedback->category?->name,
                'similarity_score' => round($score, 3),
                'issue_preview' => Str::limit($content, 180),
                'resolution' => $resolution ? Str::limit($resolution, 180) : 'Marked resolved without a detailed note.',
                'resolved_at' => $feedback->resolved_at,
            ];
        })
        ->filter(fn($item) => $item['similarity_score'] > 0)
        ->sortByDesc('similarity_score')
        ->take($limit)
        ->values();

        return response()->json([
            'success' => true,
            'suggestions' => $suggestions,
            'keywords' => array_values($keywords),
        ]);
    }

    public function recurringGroups(Request $request, FeedbackGroupingService $grouping): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'role' => ['required', 'in:hod,dean,rector'],
            'department_id' => ['nullable', 'integer'],
            'faculty_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,open,resolved'],
            'category_id' => ['nullable', 'integer', 'exists:feedback_categories,id'],
            'filter_department_id' => ['nullable', 'integer'],
            'minimum_group_size' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        if ($request->role === 'hod' && !$request->filled('department_id')) {
            return response()->json(['success' => false, 'message' => 'Department scope is required.'], 422);
        }
        if ($request->role === 'dean' && !$request->filled('faculty_id')) {
            return response()->json(['success' => false, 'message' => 'Faculty scope is required.'], 422);
        }

        $query = Feedback::with(['category', 'responses'])
            ->whereNotIn('status', ['closed'])
            ->when($request->role === 'hod', fn ($q) => $q->where(
                'recipient_department_id',
                (int) $request->department_id
            ))
            ->when($request->role === 'dean', fn ($q) => $q->where(
                'recipient_faculty_id',
                (int) $request->faculty_id
            ))
            ->when($request->filled('category_id'), fn ($q) => $q->where(
                'category_id',
                (int) $request->category_id
            ))
            ->when($request->filled('filter_department_id'), fn ($q) => $q->where(
                'recipient_department_id',
                (int) $request->filter_department_id
            ))
            ->when($request->status === 'open', fn ($q) => $q->whereNotIn('status', ['resolved', 'closed']))
            ->when($request->status === 'resolved', fn ($q) => $q->where('status', 'resolved'))
            ->orderByDesc('submitted_at')
            ->limit(500)
            ->get();

        $items = $query->map(function (Feedback $feedback) {
            $resolutionResponse = $feedback->responses
                ->where('is_escalation_note', false)
                ->sortByDesc('responded_at')
                ->first();
            $isResolutionNote = $feedback->status === 'resolved'
                && $feedback->resolved_at
                && $resolutionResponse
                && abs($resolutionResponse->responded_at->diffInMinutes($feedback->resolved_at, false)) <= 10;

            return [
                ...$this->formatFeedback($feedback),
                'recipient_faculty_id' => $feedback->recipient_faculty_id,
                'content' => $this->decryptContent($feedback->encrypted_content, $feedback->encryption_iv),
                'resolution' => $isResolutionNote
                    ? $this->decryptContent($resolutionResponse->encrypted_response, $resolutionResponse->encryption_iv)
                    : null,
            ];
        })->all();

        $minimumSize = (int) $request->input('minimum_group_size', 2);
        $groups = collect($grouping->group($items))
            ->filter(fn (array $group) => $group['feedback_count'] >= $minimumSize)
            ->values();

        return response()->json([
            'success' => true,
            'groups' => $groups,
            'summary' => [
                'feedbacks_analysed' => count($items),
                'recurring_groups' => $groups->count(),
                'grouped_feedbacks' => $groups->sum('feedback_count'),
                'groups_with_solution' => $groups->whereNotNull('suggested_solution')->count(),
                'priority_investigations' => $groups
                    ->whereIn('investigation_level', ['critical', 'high'])
                    ->count(),
                'departments_affected' => $groups->pluck('department_id')->filter()->unique()->count(),
            ],
        ]);
    }

    public function rectorReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'faculty_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $feedbacks = Feedback::query()
            ->whereNotIn('status', ['closed'])
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('submitted_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('submitted_at', '<=', $request->date_to))
            ->when($request->filled('faculty_id'), fn ($q) => $q->where('recipient_faculty_id', (int) $request->faculty_id))
            ->when($request->filled('department_id'), fn ($q) => $q->where('recipient_department_id', (int) $request->department_id))
            ->get();

        $summarize = function ($items, string $key): array {
            return $items->groupBy($key)->map(function ($group, $id) {
                $total = $group->count();
                $resolved = $group->where('status', 'resolved')->count();
                $resolvedHours = $group->filter(fn ($item) => $item->resolved_at && $item->submitted_at)
                    ->map(fn ($item) => $item->submitted_at->diffInHours($item->resolved_at));

                return [
                    'id' => $id === '' ? null : (int) $id,
                    'total' => $total,
                    'open' => $group->whereNotIn('status', ['resolved', 'closed'])->count(),
                    'resolved' => $resolved,
                    'urgent' => $group->where('priority', 'urgent')->count(),
                    'escalated' => $group->where('is_escalated', true)->count(),
                    'student' => $group->where('sender_role', 'student')->count(),
                    'lecturer' => $group->where('sender_role', 'lecturer')->count(),
                    'resolution_rate' => $total > 0 ? round(($resolved / $total) * 100, 1) : 0,
                    'average_resolution_hours' => $resolvedHours->isNotEmpty()
                        ? round($resolvedHours->average(), 1)
                        : null,
                ];
            })->sortByDesc('total')->values()->all();
        };

        $total = $feedbacks->count();
        $resolved = $feedbacks->where('status', 'resolved')->count();

        return response()->json([
            'success' => true,
            'generated_at' => now()->toIso8601String(),
            'filters' => $request->only(['date_from', 'date_to', 'faculty_id', 'department_id']),
            'summary' => [
                'total' => $total,
                'open' => $feedbacks->whereNotIn('status', ['resolved', 'closed'])->count(),
                'resolved' => $resolved,
                'urgent' => $feedbacks->where('priority', 'urgent')->count(),
                'escalated' => $feedbacks->where('is_escalated', true)->count(),
                'resolution_rate' => $total > 0 ? round(($resolved / $total) * 100, 1) : 0,
            ],
            'by_faculty' => $summarize($feedbacks, 'recipient_faculty_id'),
            'by_department' => $summarize($feedbacks, 'recipient_department_id'),
            'by_category' => $feedbacks->groupBy('category_id')->map(fn ($group, $id) => [
                'category_id' => (int) $id,
                'total' => $group->count(),
                'resolved' => $group->where('status', 'resolved')->count(),
            ])->sortByDesc('total')->values()->all(),
        ]);
    }

    // ─────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────
    private function validateAnonToken(string $plainToken): array
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->post(
                    config('services.auth_service.url') . '/api/token/validate',
                    ['anonymous_token' => $plainToken]
                );

            if ($response->successful()) {
                return ['valid' => true];
            }

            return [
                'valid'   => false,
                'message' => $response->json('message', 'Invalid or expired token.'),
            ];
        } catch (\Exception $e) {
            return [
                'valid'   => false,
                'message' => 'Could not validate token. Auth service unavailable.',
            ];
        }
    }

    private function rejectUnsafeContent(string $anonymousToken, string $content): JsonResponse
    {
        try {
            $response = Http::timeout(5)->post(
                config('services.auth_service.url') . '/api/token/content-violation',
                [
                    'anonymous_token' => $anonymousToken,
                    'content_fingerprint' => hash_hmac(
                        'sha256',
                        $content,
                        (string) config('app.key')
                    ),
                ]
            );

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'code' => 'LANGUAGE_VIOLATION',
                    'message' => config('language.first_warning'),
                ], 422);
            }

            $review = (bool) $response->json('student_affairs_review', false);

            return response()->json([
                'success' => false,
                'code' => $review
                    ? 'LANGUAGE_VIOLATION_ESCALATED'
                    : 'LANGUAGE_VIOLATION',
                'warning_count' => (int) $response->json('violation_count', 1),
                'student_affairs_review' => $review,
                'message' => $review
                    ? config('language.final_warning')
                    : config('language.first_warning'),
            ], 422);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'code' => 'LANGUAGE_VIOLATION',
                'message' => config('language.first_warning'),
            ], 422);
        }
    }

    private function encryptContent(string $content, string $iv): string
    {
        $key = config('app.feedback_encryption_key', config('app.key'));
        return base64_encode(
            openssl_encrypt($content, 'AES-256-CBC', substr($key, 0, 32), 0, substr($iv, 0, 16))
        );
    }

    private function decryptContent(string $encrypted, string $iv): string
    {
        try {
            $key = config('app.feedback_encryption_key', config('app.key'));
            return openssl_decrypt(
                base64_decode($encrypted),
                'AES-256-CBC',
                substr($key, 0, 32),
                0,
                substr($iv, 0, 16)
            ) ?: '[Content unavailable]';
        } catch (\Exception $e) {
            return '[Decryption failed]';
        }
    }

    private function formatFeedback(Feedback $f): array
    {
        return [
            'id'                      => $f->id,
            'tracking_code'           => $f->tracking_code,
            'sender_role'             => $f->sender_role,
            'sender_department_id'    => $f->sender_department_id,
            'category'                => $f->category?->name,
            'category_id'             => $f->category_id,
            'routed_to'               => $f->routed_to,
            'recipient_department_id' => $f->recipient_department_id,
            'priority'                => $f->priority,
            'status'                  => $f->status,
            'is_escalated'            => $f->is_escalated,
            'escalated_to'            => $f->escalated_to,
            'has_attachment'          => $f->has_attachment,
            'submitted_at'            => $f->submitted_at,
            'resolved_at'             => $f->resolved_at,
            'responses_count'         => $f->responses?->count() ?? 0,
        ];
    }

    private function extractKeywords(string $text): array
    {
        $normalized = Str::lower(preg_replace('/[^a-z0-9\s]/i', ' ', $text));
        $tokens = array_filter(explode(' ', $normalized), function ($token) {
            return strlen($token) > 3 && !in_array($token, [
                'that', 'this', 'with', 'have', 'from', 'your', 'please', 'about', 'there',
                'where', 'when', 'which', 'kwenye', 'kuna', 'hii', 'sana', 'kama',
            ], true);
        });

        $counts = array_count_values($tokens);
        arsort($counts);
        return array_slice(array_keys($counts), 0, 12);
    }

    private function keywordSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b)) {
            return 0;
        }
        $common = array_intersect($a, $b);
        $union = array_unique(array_merge($a, $b));
        return count($union) === 0 ? 0 : count($common) / count($union);
    }

    private function realtimeChannel(string $trackingCode): string
    {
        return hash_hmac('sha256', $trackingCode, (string) config('app.key'));
    }
}
