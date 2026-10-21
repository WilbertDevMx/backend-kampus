<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// `role` no es asignable en masa a propósito: el registro público nunca puede crear staff (RF-22).
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Mismo valor por defecto que la columna, para que el modelo recién creado ya tenga su rol.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'student',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Universidad del alumno; en un moderador, la que modera. Un administrador no tiene (las ve todas).
     *
     * @return BelongsTo<University, $this>
     */
    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isModerator(): bool
    {
        return $this->role === UserRole::Moderator;
    }

    /**
     * Usuarios que `$actor` puede consultar y moderar: un administrador, a todos menos a sí mismo; un
     * moderador, solo a los estudiantes de su universidad; un estudiante, a nadie.
     *
     * @param  Builder<User>  $query
     */
    public function scopeManageableBy(Builder $query, User $actor): void
    {
        $query->whereKeyNot($actor->getKey());

        match ($actor->role) {
            UserRole::Admin => null,
            UserRole::Moderator => $query
                ->where('role', UserRole::Student)
                ->where('university_id', $actor->university_id),
            UserRole::Student => $query->whereRaw('1 = 0'),
        };
    }
}
