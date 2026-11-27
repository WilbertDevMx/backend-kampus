<?php

namespace App\Accounts;

use App\Enums\GroupMemberRole;
use App\Jobs\DeleteStoredFiles;
use App\Models\Group;
use App\Models\Message;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Elimina una cuenta de estudiante con todos sus datos (T-23, RF-16). La usan el propio estudiante desde
 * Ajustes y el staff desde el panel (T-43).
 *
 * El borrado en la base es inmediato y los `CASCADE` se llevan perfil, fotos, intereses, ubicación, swipes,
 * matches con sus mensajes, bloqueos, membresías y los reportes en su contra. Antes de borrar:
 *
 * - Se juntan las rutas de las fotos del perfil y de las imágenes de sus chats; se borran del bucket por la
 *   cola (`DeleteStoredFiles`), con reintentos, para cumplir el plazo de 72 horas aunque el bucket falle.
 * - En sus grupos, si era el único integrante se borra el grupo; si era el único administrador, pasa el
 *   rol al integrante más antiguo, igual que al salir del grupo.
 * - Se cierran sus sesiones y se borra su token de recuperación de contraseña.
 *
 * Lo que queda: los reportes que hizo (sin autor), los grupos que creó (sin creador) y la bitácora de
 * moderación, que solo guarda su id.
 */
class AccountDeletion
{
    public function delete(User $user): void
    {
        $files = DB::transaction(function () use ($user): array {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $files = [
                [config('filesystems.profile_photos_disk'), $this->profilePhotoPaths($user)],
                [config('filesystems.chat_images_disk'), $this->chatImagePaths($user)],
                [config('filesystems.group_images_disk'), $this->leaveGroups($user)],
            ];

            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            $user->delete();

            return $files;
        });

        // Después del commit (también el del panel, que envuelve esta transacción): si algo fallara, los
        // archivos no deben desaparecer.
        foreach ($this->groupByDisk($files) as $disk => $paths) {
            DeleteStoredFiles::dispatch($disk, $paths)->afterCommit();
        }

        Log::channel('security')->notice('Cuenta eliminada.', ['user_id' => $user->getKey()]);
    }

    /**
     * @return list<string>
     */
    private function profilePhotoPaths(User $user): array
    {
        return Photo::query()
            ->whereHas('profile', fn ($profile) => $profile->where('user_id', $user->getKey()))
            ->pluck('path')
            ->all();
    }

    /**
     * Imágenes de todos los chats de la cuenta, también las que mandó la otra persona: el match y sus
     * mensajes se borran completos.
     *
     * @return list<string>
     */
    private function chatImagePaths(User $user): array
    {
        return Message::query()
            ->whereIn('match_id', DB::table('matches')
                ->select('id')
                ->where('user_one_id', $user->getKey())
                ->orWhere('user_two_id', $user->getKey()))
            ->whereNotNull('attachment_path')
            ->pluck('attachment_path')
            ->all();
    }

    /**
     * Saca a la cuenta de sus grupos sin dejarlos sin administrador. Devuelve las imágenes de los grupos
     * que se borraron por quedarse vacíos.
     *
     * @return list<string>
     */
    private function leaveGroups(User $user): array
    {
        $images = [];
        $memberships = DB::table('group_members')->where('user_id', $user->getKey())->get(['group_id', 'role']);

        foreach ($memberships as $membership) {
            $group = Group::query()->lockForUpdate()->find($membership->group_id);

            if ($group === null) {
                continue;
            }

            $others = fn () => $group->members()->whereKeyNot($user->getKey());

            if (! $others()->exists()) {
                if ($group->image_path !== null) {
                    $images[] = $group->image_path;
                }

                $group->delete();

                continue;
            }

            $wasOnlyAdmin = $membership->role === GroupMemberRole::Admin->value
                && ! $others()->wherePivot('role', GroupMemberRole::Admin->value)->exists();

            if ($wasOnlyAdmin) {
                $successor = $others()->orderBy('group_members.created_at')->orderBy('users.id')->first();
                $group->members()->updateExistingPivot($successor->getKey(), ['role' => GroupMemberRole::Admin->value]);
            }
        }

        return $images;
    }

    /**
     * Junta las rutas por disco: normalmente los tres son el mismo bucket.
     *
     * @param  list<array{0: string, 1: list<string>}>  $files
     * @return array<string, list<string>>
     */
    private function groupByDisk(array $files): array
    {
        $byDisk = [];

        foreach ($files as [$disk, $paths]) {
            if ($paths !== []) {
                $byDisk[$disk] = [...($byDisk[$disk] ?? []), ...$paths];
            }
        }

        return $byDisk;
    }
}
