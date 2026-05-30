<?php
namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\FeedbackFollowup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TrackingController extends Controller
{
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
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

        FeedbackFollowup::create([
            'feedback_id'       => $feedback->id,
            'tracking_code'     => $feedback->tracking_code,
            'encrypted_message' => $encrypted,
            'encryption_iv'     => $iv,
            'direction'         => $request->direction,
            'sent_at'           => now(),
        ]);

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
}