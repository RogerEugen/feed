<?php
namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackCategory;
use App\Models\FeedbackResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

class FeedbackController extends Controller
{
    // ─────────────────────────────────────────────
    // GET CATEGORIES (for form dropdown)
    // ─────────────────────────────────────────────
    public function categories(Request $request): JsonResponse
    {
        $role = $request->query('role', 'any');

        $categories = FeedbackCategory::where('is_active', true)
            ->where(function ($q) use ($role) {
                $q->where('sender_role', $role)
                  ->orWhere('sender_role', 'any');
            })
            ->get(['id', 'name', 'slug', 'routes_to', 'sender_role', 'description']);

        return response()->json([
            'success'    => true,
            'categories' => $categories,
        ]);
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
                    'comntent'   => $this->decryptContent($f->encrypted_message, $f->encryption_iv),
                    'sent_at'   => $f->sent_at,
                ]),
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

        $feedback->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Feedback marked as resolved.',
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
}
