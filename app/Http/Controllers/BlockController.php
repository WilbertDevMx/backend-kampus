<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Block;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Bloquear y desbloquear estudiantes (T-21, RF-13).
 *
 * Un bloqueo, en cualquier sentido, saca a las dos personas del descubrimiento, de la lista de matches y del
 * chat. No se le avisa a la persona bloqueada.
 */
#[Group('Bloqueo y reporte', 'Bloquear, desbloquear y reportar estudiantes (T-21).', weight: 10)]
class BlockController extends Controller
{
    /**
     * Personas que el alumno bloqueó, de la más reciente a la más antigua.
     */
    public function index(Request $request): JsonResponse
    {
        $blocks = Block::query()
            ->where('blocker_id', $request->user()->getKey())
            ->with('blocked:id,name')
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json([
            'data' => $blocks->getCollection()->map(fn (Block $block): array => [
                'user_id' => $block->blocked_id,
                'name' => $block->blocked?->name,
                'blocked_at' => $block->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $blocks->currentPage(),
                'last_page' => $blocks->lastPage(),
                'per_page' => $blocks->perPage(),
                'total' => $blocks->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $target = self::blockableStudent($request->user(), (int) $validated['user_id']);
        $block = Block::query()->firstOrCreate([
            'blocker_id' => $request->user()->getKey(),
            'blocked_id' => $target->getKey(),
        ]);

        return response()->json([
            'data' => [
                'user_id' => $target->getKey(),
                'blocked_at' => $block->created_at?->toIso8601String(),
            ],
        ], $block->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $user): Response
    {
        $deleted = Block::query()
            ->where('blocker_id', $request->user()->getKey())
            ->where('blocked_id', $user)
            ->delete();

        abort_if($deleted === 0, 404, 'No tienes bloqueada a esta persona.');

        return response()->noContent();
    }

    /**
     * Solo se bloquea o reporta a otro estudiante. Mismo mensaje si no existe o es staff, para no revelar nada.
     *
     * @throws ValidationException
     */
    public static function blockableStudent(User $actor, int $userId): User
    {
        $target = User::query()
            ->where('role', UserRole::Student)
            ->whereKeyNot($actor->getKey())
            ->find($userId);

        if ($target === null) {
            throw ValidationException::withMessages([
                'user_id' => 'Este perfil no está disponible.',
            ]);
        }

        return $target;
    }
}
