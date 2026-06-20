<?php

namespace App\Http\Controllers;

use App\Events\CommunicationMessageSent;
use App\Models\CommunicationMessage;
use App\Models\CommunicationReadState;
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

        $validator = Validator::make($request->all(), [
            'sender_role' => ['required', 'in:hod,dean,rector'],
            'actor_id' => ['required', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!$this->roleCanJoin($request->sender_role, (int) $request->actor_id, $room)) {
            return response()->json(['message' => 'You cannot access this private conversation.'], 403);
        }

        $messages = CommunicationMessage::where('room', $room)
            ->orderByDesc('sent_at')
            ->limit(100)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (CommunicationMessage $message) => $this->format($message));

        if ($request->boolean('mark_read', true)) {
            $this->markRoomRead($room, $request->sender_role, (int) $request->actor_id);
        }

        return response()->json([
            'success' => true,
            'room' => $room,
            'realtime_channel' => $this->channel($room),
            'messages' => $messages,
            'unread_count' => $this->unreadCount(
                $room,
                $request->sender_role,
                (int) $request->actor_id
            ),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        if (!$this->authorizedService($request)) {
            return response()->json(['message' => 'Unauthorized communication request.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'sender_role' => ['required', 'in:hod,dean,rector'],
            'actor_id' => ['required', 'integer', 'min:1'],
            'rooms' => ['required', 'array', 'max:100'],
            'rooms.*' => ['required', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rooms = collect($request->rooms)
            ->unique()
            ->filter(fn (string $room) => $this->validRoom($room))
            ->filter(fn (string $room) => $this->roleCanJoin(
                $request->sender_role,
                (int) $request->actor_id,
                $room
            ))
            ->map(fn (string $room) => [
                'key' => $room,
                'unread_count' => $this->unreadCount(
                    $room,
                    $request->sender_role,
                    (int) $request->actor_id
                ),
                'realtime_channel' => $this->channel($room),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'rooms' => $rooms,
            'total_unread' => $rooms->sum('unread_count'),
        ]);
    }

    public function markRead(Request $request, string $room): JsonResponse
    {
        if (!$this->authorized($request, $room)) {
            return response()->json(['message' => 'Unauthorized communication room.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'sender_role' => ['required', 'in:hod,dean,rector'],
            'actor_id' => ['required', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        if (!$this->roleCanJoin($request->sender_role, (int) $request->actor_id, $room)) {
            return response()->json(['message' => 'You cannot access this private conversation.'], 403);
        }

        $this->markRoomRead($room, $request->sender_role, (int) $request->actor_id);

        return response()->json(['success' => true, 'unread_count' => 0]);
    }

    public function store(Request $request, string $room): JsonResponse
    {
        if (!$this->authorized($request, $room)) {
            return response()->json(['message' => 'Unauthorized communication room.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'sender_role' => ['required', 'in:hod,dean,rector'],
            'actor_id' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'min:2', 'max:3000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!$this->roleCanJoin($request->sender_role, (int) $request->actor_id, $room)) {
            return response()->json(['message' => 'You cannot access this private conversation.'], 403);
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
        return $this->authorizedService($request) && $this->validRoom($room);
    }

    private function authorizedService(Request $request): bool
    {
        $configured = (string) config('services.view_service.key');
        $provided = (string) $request->header('X-View-Service-Key');

        return $configured !== '' && hash_equals($configured, $provided);
    }

    private function validRoom(string $room): bool
    {
        return preg_match('/^leadership\.(hod|dean)\.\d+$/', $room) === 1;
    }

    private function roleCanJoin(string $role, int $actorId, string $room): bool
    {
        if (!preg_match('/^leadership\.(hod|dean)\.(\d+)$/', $room, $matches)) {
            return false;
        }

        $participantRole = $matches[1];
        $participantId = (int) $matches[2];

        if ($role === 'rector') {
            return true;
        }

        return $role === $participantRole && $actorId === $participantId;
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

    private function unreadCount(string $room, string $role, int $actorId): int
    {
        $lastReadId = (int) CommunicationReadState::where([
            'room' => $room,
            'actor_role' => $role,
            'actor_id' => $actorId,
        ])->value('last_read_message_id');

        return CommunicationMessage::where('room', $room)
            ->where('id', '>', $lastReadId)
            ->where('sender_role', '!=', $role)
            ->count();
    }

    private function markRoomRead(string $room, string $role, int $actorId): void
    {
        $latestIncomingId = (int) CommunicationMessage::where('room', $room)
            ->where('sender_role', '!=', $role)
            ->max('id');

        CommunicationReadState::updateOrCreate(
            [
                'room' => $room,
                'actor_role' => $role,
                'actor_id' => $actorId,
            ],
            [
                'last_read_message_id' => $latestIncomingId,
                'read_at' => now(),
            ]
        );
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
