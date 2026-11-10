<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

// `role` no es asignable en masa a propósito: el registro público nunca puede crear staff (RF-22).
#[Fillable(['name', 'email', 'password', 'university_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * Celda de ~1 km de "Cerca de ti" (T-28). Solo existe mientras la función está activa.
     */
    public function location(): HasOne
    {
        return $this->hasOne(UserLocation::class);
    }

    /**
     * Lo llama el listener de `Registered`; se reemplaza para mandar el correo por la cola.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Lo llama `Password::sendResetLink()`; se reemplaza para mandar el correo en español y por la cola (T-45).
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

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

    /**
     * Estudiantes que `$viewer` puede ver en el descubrimiento y evaluar (RF-05): con correo verificado,
     * sin suspensión, con perfil y sin bloqueo en ningún sentido (RF-13). Nunca incluye al staff ni a
     * `$viewer`. Los perfiles que ya evaluó los quita `App\Discovery\DiscoveryQuery`.
     *
     * @param  Builder<User>  $query
     */
    public function scopeDiscoverableBy(Builder $query, User $viewer): void
    {
        $viewerId = $viewer->getKey();

        $query->whereKeyNot($viewerId)
            ->where('users.role', UserRole::Student)
            ->whereNotNull('users.email_verified_at')
            ->whereNull('users.suspended_at')
            ->whereHas('profile')
            ->whereNotExists(fn (QueryBuilder $blocks) => $blocks->select(DB::raw(1))
                ->from('blocks')
                ->where(fn (QueryBuilder $pair) => $pair
                    ->where(fn (QueryBuilder $byViewer) => $byViewer
                        ->where('blocks.blocker_id', $viewerId)
                        ->whereColumn('blocks.blocked_id', 'users.id'))
                    ->orWhere(fn (QueryBuilder $byOther) => $byOther
                        ->whereColumn('blocks.blocker_id', 'users.id')
                        ->where('blocks.blocked_id', $viewerId))));
    }
}
