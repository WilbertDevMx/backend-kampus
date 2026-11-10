<?php

namespace App\Models;

use App\Enums\GroupCategory;
use App\Enums\GroupMemberRole;
use App\Enums\GroupStatus;
use App\Enums\UserRole;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Grupo o club (RF-11, RF-12).
 *
 * El grupo de un alumno nace en `pending` y solo se publica cuando lo aprueba un moderador de su
 * universidad o un administrador (T-42, RF-23). `status`, `is_official` y los datos de la revisión no son
 * asignables en masa: solo los cambia la aprobación.
 */
#[Fillable(['university_id', 'created_by', 'name', 'description', 'category', 'image_path'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /**
     * Mismos valores por defecto que la tabla, para que el modelo recién creado ya los tenga.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'is_official' => false,
    ];

    protected function casts(): array
    {
        return [
            'category' => GroupCategory::class,
            'status' => GroupStatus::class,
            'is_official' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(GroupActivity::class);
    }

    public function isApproved(): bool
    {
        return $this->status === GroupStatus::Approved;
    }

    /**
     * Rol de `$user` en el grupo, o `null` si no es integrante.
     */
    public function roleOf(User $user): ?GroupMemberRole
    {
        $role = DB::table('group_members')
            ->where('group_id', $this->getKey())
            ->where('user_id', $user->getKey())
            ->value('role');

        return $role === null ? null : GroupMemberRole::from($role);
    }

    /**
     * URL firmada de 15 minutos de la imagen del grupo: está en un bucket privado (RNF-03).
     */
    public function imageUrl(): ?string
    {
        if ($this->image_path === null) {
            return null;
        }

        return Storage::disk(config('filesystems.group_images_disk'))
            ->temporaryUrl($this->image_path, now()->addMinutes(15));
    }

    /**
     * @param  Builder<Group>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('groups.status', GroupStatus::Approved);
    }

    /**
     * Grupos que `$user` puede ver: los aprobados y, además, aquellos de los que es integrante
     * (así el creador sigue su grupo mientras está en revisión o si se lo rechazan).
     *
     * @param  Builder<Group>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $visible) => $visible
            ->where('groups.status', GroupStatus::Approved)
            ->orWhereHas('members', fn (Builder $members) => $members->whereKey($user->getKey())));
    }

    /**
     * Grupos que `$staff` puede revisar (RF-23): un administrador, todos; un moderador, solo los de su
     * universidad; un estudiante, ninguno.
     *
     * @param  Builder<Group>  $query
     */
    public function scopeReviewableBy(Builder $query, User $staff): void
    {
        match ($staff->role) {
            UserRole::Admin => null,
            UserRole::Moderator => $staff->university_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('groups.university_id', $staff->university_id),
            UserRole::Student => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Agrega `my_role` (rol de `$user` en cada grupo) y `members_count`, sin una consulta por grupo.
     *
     * @param  Builder<Group>  $query
     */
    public function scopeForCard(Builder $query, User $user): void
    {
        $query->select('groups.*')
            ->addSelect(['my_role' => DB::table('group_members')
                ->select('role')
                ->whereColumn('group_members.group_id', 'groups.id')
                ->where('group_members.user_id', $user->getKey())
                ->limit(1)])
            ->withCount('members')
            ->with('university');
    }
}
