<?php

namespace App\Http\Controllers;

use App\Events\CommunicationMessageSent;
use App\Models\CommunicationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CommunicationController extends Controller
{
    public function index(Request $request, string $room): JsonResponse
    {
        if (!$this->authorized($request, $room)) {
            return response()->json(['message' => 'Unauthorized communication room.'], 403);
        }

        $messages = CommunicationMessage::where('room', $room)
            ->orderByDesc('sent_at')
            ->limit(100)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (CommunicationMessage $message) => $this->format($message));

        return response()->json([
            'success' => true,
            'room' => $room,
            'realtime_channel' => $this->channel($room),
            'messages' => $messages,
        ]);
    }

    public function store(Request $request, string $room): JsonResponse
    {
        if (!$this->authorized($request, $room)) {
            return response()->json(['message' => 'Unauthorized communication room.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'sender_role' => ['required', 'in:hod,dean,rector,lecturer'],
            'message' => ['required', 'string', 'min:2', 'max:3000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!$this->roleCanJoin($request->sender_role, $room)) {
            return response()->json(['message' => 'Your role cannot use this room.'], 403);
        }

        $iv = bin2hex(random_bytes(8));
        $message = CommunicationMessage::create([
            'room' => $room,
            'sender_role' => $request->sender_role,
            'encrypted_message' => $this->encrypt($request->message, $iv),
            'encryption_iv' => $iv,
            'sent_at' => now(),
        ]);

        $payload = $this->format($message);
        broadcast(new CommunicationMessageSent($this->channel($room), $payload));

        return response()->json(['success' => true, 'message' => $payload], 201);
    }

    private function authorized(Request $request, string $room): bool
    {
        $configured = (string) config('services.view_service.key');
        $provided = (string) $request->header('X-View-Service-Key');

        return $configured !== ''
            && hash_equals($configured, $provided)
            && preg_match('/^(campus\.leadership|faculty\.\d+\.leadership)$/', $room);
    }

    private function roleCanJoin(string $role, string $room): bool
    {
        if ($room === 'campus.leadership') {
            return in_array($role, ['hod', 'dean', 'rector'], true);
        }

        return str_starts_with($room, 'faculty.')
            && in_array($role, ['hod', 'dean', 'rector'], true);
    }

    private function format(CommunicationMessage $message): array
    {
        return [
            'id' => $message->id,
            'sender_role' => $message->sender_role,
            'content' => $this->decrypt($message->encrypted_message, $message->encryption_iv),
            'sent_at' => $message->sent_at,
        ];
    }

    private function channel(string $room): string
    {
        return hash_hmac('sha256', $room, (string) config('app.key'));
    }

    private function encrypt(string $content, string $iv): string
    {
        $key = config('app.feedback_encryption_key', config('app.key'));

        return base64_encode(openssl_encrypt(
            $content,
            'AES-256-CBC',
            substr($key, 0, 32),
            0,
            substr($iv, 0, 16)
        ));
    }

    private function decrypt(string $content, string $iv): string
    {
        $key = config('app.feedback_encryption_key', config('app.key'));

        return openssl_decrypt(
            base64_decode($content),
            'AES-256-CBC',
            substr($key, 0, 32),
            0,
            substr($iv, 0, 16)
        ) ?: '[Message unavailable]';
    }
}
