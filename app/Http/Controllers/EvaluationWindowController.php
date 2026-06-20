<?php
namespace App\Http\Controllers;

use App\Models\EvaluationWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class EvaluationWindowController extends Controller
{
    // ── List all windows ───────────────────────────────────────
    public function index(): JsonResponse
    {
        $windows = EvaluationWindow::orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'windows' => $windows->map(fn($w) => $this->formatWindow($w)),
        ]);
    }

    // ── Get currently open window for students ─────────────────
    public function active(): JsonResponse
    {
        // ✅ Find any active window where current time is within range
        $window = EvaluationWindow::where('is_active', true)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>=', now())
            ->first();

        Log::info('Active window check', [
            'now'    => now()->toDateTimeString(),
            'found'  => $window ? $window->id : null,
        ]);

        if (!$window) {
            return response()->json([
                'success' => false,
                'message' => 'No evaluation window is currently open.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'window'  => $this->formatWindow($window),
        ]);
    }

    // ── Create window ──────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title'         => ['required', 'string', 'max:150'],
            'academic_year' => ['required', 'string', 'regex:/^\d{4}\/\d{4}$/'],
            'semester'      => ['required', 'integer', 'in:1,2'],
            'opens_at'      => ['required', 'date'],         // ✅ removed after_or_equal:now
            'closes_at'     => ['required', 'date', 'after:opens_at'],
            'is_active'     => ['sometimes', 'boolean'],
        ], [
            'academic_year.regex' => 'Academic year must be in format 2024/2025.',
            'closes_at.after'     => 'Close date must be after open date.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Deactivate other windows if this one is active
        $isActive = $request->boolean('is_active', true);
        if ($isActive) {
            EvaluationWindow::where('is_active', true)->update(['is_active' => false]);
        }

        $window = EvaluationWindow::create([
            'title'         => $request->title,
            'academic_year' => $request->academic_year,
            'semester'      => (int) $request->semester,
            'opens_at'      => $request->opens_at,
            'closes_at'     => $request->closes_at,
            'is_active'     => $isActive,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evaluation window created successfully.',
            'window'  => $this->formatWindow($window),
        ], 201);
    }

    // ── Toggle active ──────────────────────────────────────────
    public function toggle(int $id): JsonResponse
    {
        $window = EvaluationWindow::findOrFail($id);

        if (!$window->is_active) {
            if ($window->closes_at->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A closed evaluation window cannot be activated again.',
                ], 422);
            }
            // Activating this one — deactivate all others
            EvaluationWindow::where('is_active', true)
                ->where('id', '!=', $id)
                ->update(['is_active' => false]);
        }

        $window->update(['is_active' => !$window->is_active]);

        return response()->json([
            'success' => true,
            'message' => $window->fresh()->is_active
                ? 'Window activated successfully.'
                : 'Window deactivated.',
            'window'  => $this->formatWindow($window->fresh()),
        ]);
    }

    // ── Delete ─────────────────────────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $window = EvaluationWindow::findOrFail($id);

        if ($window->evaluations()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a window that has received evaluations.',
            ], 422);
        }

        $window->delete();

        return response()->json([
            'success' => true,
            'message' => 'Window deleted successfully.',
        ]);
    }

    // ── Private helper ─────────────────────────────────────────
    private function formatWindow(EvaluationWindow $w): array
    {
        $now = now();

        $isOpen = $w->is_active
            && $now->gte($w->opens_at)
            && $now->lte($w->closes_at);

        return [
            'id'            => $w->id,
            'title'         => $w->title,
            'academic_year' => $w->academic_year,
            'semester'      => $w->semester,
            'opens_at'      => $w->opens_at,
            'closes_at'     => $w->closes_at,
            'is_active'     => $w->is_active,
            'is_open'       => $isOpen,
            'created_at'    => $w->created_at,
        ];
    }
}
