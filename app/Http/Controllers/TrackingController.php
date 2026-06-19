<?php
namespace App\Http\Controllers;

use App\Events\FeedbackMessageSent;
use App\Models\Feedback;
use App\Models\FeedbackFollowup;
use App\Services\LanguageModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class TrackingController extends Controller
{
    public function lecturerThread(Request $request, string $code): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'anonymous_token' => ['required', 'string', 'min:10'],
            'sender_department_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $session = $this->validateAnonymousSession($request->anonymous_token);
        if (!$session['valid'] || ($session['role'] ?? null) !== 'lecturer') {
            return response()->json([
                'success' => false,
                'message' => $session['message'] ?? 'The anonymous lecturer session is invalid.',
            ], 401);
        }

        $feedback = $this->findLecturerFeedback($code);
        if (!$feedback) {
            return response()->json([
                'success' => false,
                'message' => 'No lecturer feedback was found for this tracking code.',
            ], 404);
        }

        if (
            $request->filled('sender_department_id')
            && $feedback->sender_department_id
            && (int) $request->sender_department_id !== (int) $feedback->sender_department_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This tracking code does not belong to your department.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'thread' => $this->threadPayload($feedback),
        ]);
    }

    public function rectorThreads(Request $request): JsonResponse
    {
        if (!$this->authorizedViewService($request)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $threads = Feedback::query()
            ->with(['category', 'responses', 'followups'])
            ->where('sender_role', 'lecturer')
            ->whereIn('routed_to', ['rector', 'admin'])
            ->orderByDesc('submitted_at')
            ->limit(150)
            ->get()
            ->map(function (Feedback $feedback) {
                $lastResponse = $feedback->responses->max('responded_at');
                $lastFollowup = $feedback->followups->max('sent_at');
                $lastActivity = collect([
                    $feedback->submitted_at,
                    $lastResponse,
                    $lastFollowup,
                ])->filter()->max();

                return [
                    'tracking_code' => $feedback->tracking_code,
                    'category' => $feedback->category?->name,
                    'status' => $feedback->status,
                    'priority' => $feedback->priority,
                    'submitted_at' => $feedback->submitted_at,
                    'last_activity_at' => $lastActivity,
                    'messages_count' => $feedback->responses->count() + $feedback->followups->count(),
                ];
            })
            ->sortByDesc('last_activity_at')
            ->values();

        return response()->json(['success' => true, 'threads' => $threads]);
    }

    public function rectorThread(Request $request, string $code): JsonResponse
    {
        if (!$this->authorizedViewService($request)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $feedback = $this->findLecturerFeedback($code);
        if (!$feedback) {
            return response()->json(['message' => 'Lecturer feedback thread not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'thread' => $this->threadPayload($feedback),
        ]);
    }

    public function rectorReply(Request $request, string $code): JsonResponse
    {
        if (!$this->authorizedViewService($request)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'min:2', 'max:3000'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $moderation = app(LanguageModerationService::class)->inspect($request->message);
        if ($moderation['violates']) {
            return response()->json([
                'success' => false,
                'message' => 'The message contains prohibited language. Please rewrite it respectfully.',
            ], 422);
        }

        $feedback = $this->findLecturerFeedback($code);
        if (!$feedback) {
            return response()->json(['message' => 'Lecturer feedback thread not found.'], 404);
        }

        $iv = bin2hex(random_bytes(8));
        $response = $feedback->responses()->create([
            'responder_role' => 'rector',
            'responder_department_id' => null,
            'encrypted_response' => $this->encryptContent($request->message, $iv),
            'encryption_iv' => $iv,
            'is_escalation_note' => false,
            'responded_at' => now(),
        ]);
        $feedback->update(['status' => 'under_review']);

        $message = [
            'id' => 'response-' . $response->id,
            'sender_role' => 'rector',
            'content' => $request->message,
            'sent_at' => $response->responded_at,
        ];

        broadcast(new FeedbackMessageSent(
            $this->realtimeChannel($feedback->tracking_code),
            $message
        ));

        return response()->json(['success' => true, 'message' => $message], 201);
    }

    // ─────────────────────────────────────────────
    // TRACK BY CODE — validates role ownership
    // ─────────────────────────────────────────────
    public function track(Request $request, string $code): JsonResponse
    {
        $senderRole = $request->query('sender_role'); // passed by Views Service

        $feedback = Feedback::where('tracking_code', strtoupper($code))
            ->with(['category', 'responses', 'followups'])
            ->first();

        if (!$feedback) {
            return response()->json([
                'success' => false,
                'message' => 'Tracking code not found.',
            ], 404);
        }

        // ── ROLE OWNERSHIP CHECK ──────────────────────────────
        // Only the role that submitted can track it
        if ($senderRole && $feedback->sender_role !== $senderRole) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view this feedback.',
                'reason'  => 'role_mismatch',
            ], 403);
        }

        // ── ROUTE VALIDATION ─────────────────────────────────
        // Verify the tracking code matches what that role is allowed to submit
        $allowedRoutes = $this->getAllowedRoutes($senderRole);

        if ($senderRole && !in_array($feedback->routed_to, $allowedRoutes)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view this feedback.',
                'reason'  => 'route_mismatch',
            ], 403);
        }

        return response()->json([
            'success'  => true,
            'feedback' => [
                'tracking_code' => $feedback->tracking_code,
                'category'      => $feedback->category?->name,
                'status'        => $feedback->status,
                'priority'      => $feedback->priority,
                'routed_to'     => $feedback->routed_to,
                'is_escalated'  => $feedback->is_escalated,
                'submitted_at'  => $feedback->submitted_at,
                'resolved_at'   => $feedback->resolved_at,
                'realtime_channel' => $this->realtimeChannel($feedback->tracking_code),
                'responses'     => $feedback->responses->map(fn($r) => [
                    'responder_role' => $r->responder_role,
                    'responded_at'   => $r->responded_at,
                    'content'        => $this->decryptContent(
                        $r->encrypted_response,
                        $r->encryption_iv
                    ),
                ]),
                'followups' => $feedback->followups->map(fn($f) => [
                    'direction' => $f->direction,
                    'sent_at'   => $f->sent_at,
                    'content'   => $this->decryptContent(
                        $f->encrypted_message,
                        $f->encryption_iv
                    ),
                ]),
            ],
        ]);
    }

    // ─────────────────────────────────────────────
    // FOLLOW-UP — also validates role
    // ─────────────────────────────────────────────
    public function followup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tracking_code'        => ['required', 'string'],
            'message'              => ['required', 'string', 'min:5', 'max:2000'],
            'direction'            => ['required', 'in:sender_to_recipient,recipient_to_sender'],
            'sender_role'          => ['required', 'string'],
            'sender_department_id' => ['nullable', 'integer'],
            'anonymous_token'      => ['required', 'string', 'min:10'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $moderation = app(LanguageModerationService::class)->inspect($request->message);
        if ($moderation['violates']) {
            return $this->rejectUnsafeContent($request->anonymous_token, $request->message);
        }

        $session = $this->validateAnonymousSession($request->anonymous_token);
        if (!$session['valid'] || ($session['role'] ?? null) !== $request->sender_role) {
            return response()->json([
                'success' => false,
                'message' => $session['message'] ?? 'The anonymous session is invalid.',
            ], 401);
        }

        $feedback = Feedback::where('tracking_code', strtoupper($request->tracking_code))
            ->first();

        if (!$feedback) {
            return response()->json([
                'success' => false,
                'message' => 'Tracking code not found.',
            ], 404);
        }

        // ── ROLE CHECK ────────────────────────────────────────────
        if ($feedback->sender_role !== $request->sender_role) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to follow up on this feedback.',
                'reason'  => 'role_mismatch',
            ], 403);
        }

        // ── DEPARTMENT CHECK ──────────────────────────────────────
        // Students from different departments cannot follow up on each other's feedback
        if (
            $request->sender_department_id &&
            $feedback->sender_department_id &&
            (int) $feedback->sender_department_id !== (int) $request->sender_department_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You can only follow up on feedback from your own department.',
                'reason'  => 'department_mismatch',
            ], 403);
        }

        $iv        = bin2hex(random_bytes(8));
        $encrypted = $this->encryptContent($request->message, $iv);

        $followup = FeedbackFollowup::create([
            'feedback_id'       => $feedback->id,
            'tracking_code'     => $feedback->tracking_code,
            'encrypted_message' => $encrypted,
            'encryption_iv'     => $iv,
            'direction'         => $request->direction,
            'sent_at'           => now(),
        ]);

        broadcast(new FeedbackMessageSent(
            $this->realtimeChannel($feedback->tracking_code),
            [
                'id' => 'followup-' . $followup->id,
                'type' => 'followup',
                'sender_role' => $request->sender_role,
                'direction' => $request->direction,
                'content' => $request->message,
                'sent_at' => now()->toIso8601String(),
            ]
        ));

        return response()->json([
            'success' => true,
            'message' => 'Follow-up message sent.',
        ]);
    }

    // ─────────────────────────────────────────────
    // PRIVATE — allowed routes per role
    // ─────────────────────────────────────────────
    private function getAllowedRoutes(?string $role): array
    {
        return match ($role) {
            'student'  => ['hod', 'dean', 'admin'],   // student sends to hod or dean
            'lecturer' => ['rector', 'admin'],          // lecturer sends to rector only
            default    => ['hod', 'dean', 'rector', 'admin'],
        };
    }

    private function findLecturerFeedback(string $code): ?Feedback
    {
        return Feedback::where('tracking_code', strtoupper(trim($code)))
            ->where('sender_role', 'lecturer')
            ->whereIn('routed_to', ['rector', 'admin'])
            ->with(['category', 'responses', 'followups'])
            ->first();
    }

    private function threadPayload(Feedback $feedback): array
    {
        $messages = collect();

        foreach ($feedback->responses as $response) {
            $messages->push([
                'id' => 'response-' . $response->id,
                'sender_role' => $response->responder_role,
                'content' => $this->decryptContent($response->encrypted_response, $response->encryption_iv),
                'sent_at' => $response->responded_at,
            ]);
        }

        foreach ($feedback->followups as $followup) {
            $messages->push([
                'id' => 'followup-' . $followup->id,
                'sender_role' => $followup->direction === 'sender_to_recipient' ? 'lecturer' : 'rector',
                'content' => $this->decryptContent($followup->encrypted_message, $followup->encryption_iv),
                'sent_at' => $followup->sent_at,
            ]);
        }

        return [
            'tracking_code' => $feedback->tracking_code,
            'category' => $feedback->category?->name,
            'status' => $feedback->status,
            'priority' => $feedback->priority,
            'submitted_at' => $feedback->submitted_at,
            'content' => $this->decryptContent($feedback->encrypted_content, $feedback->encryption_iv),
            'realtime_channel' => $this->realtimeChannel($feedback->tracking_code),
            'messages' => $messages->sortBy('sent_at')->values(),
        ];
    }

    private function authorizedViewService(Request $request): bool
    {
        $configured = (string) config('services.view_service.key');
        $provided = (string) $request->header('X-View-Service-Key');

        return $configured !== '' && hash_equals($configured, $provided);
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

    private function encryptContent(string $content, string $iv): string
    {
        $key = config('app.feedback_encryption_key', config('app.key'));
        return base64_encode(
            openssl_encrypt($content, 'AES-256-CBC', substr($key, 0, 32), 0, substr($iv, 0, 16))
        );
    }

    private function realtimeChannel(string $trackingCode): string
    {
        return hash_hmac('sha256', $trackingCode, (string) config('app.key'));
    }

    private function validateAnonymousSession(string $token): array
    {
        try {
            $response = Http::timeout(5)->post(
                config('services.auth_service.url') . '/api/token/validate-evaluation',
                ['anonymous_token' => $token]
            );

            return $response->successful()
                ? ['valid' => true, ...$response->json()]
                : ['valid' => false, 'message' => $response->json('message')];
        } catch (\Throwable) {
            return ['valid' => false, 'message' => 'Authentication service unavailable.'];
        }
    }

    private function rejectUnsafeContent(string $token, string $content): JsonResponse
    {
        try {
            $response = Http::timeout(5)->post(
                config('services.auth_service.url') . '/api/token/content-violation',
                [
                    'anonymous_token' => $token,
                    'content_fingerprint' => hash_hmac('sha256', $content, (string) config('app.key')),
                ]
            );
            $review = $response->successful()
                && (bool) $response->json('student_affairs_review', false);

            return response()->json([
                'success' => false,
                'code' => $review ? 'LANGUAGE_VIOLATION_ESCALATED' : 'LANGUAGE_VIOLATION',
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
}
