<?php

namespace App\Models;

use App\Enums\Gender;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'career',
    'semester',
    'birthdate',
    'gender',
    'bio',
    'zone_id',
    'nearby_enabled',
    'discovery_filters',
])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /** Edad mínima para usar Kampus (T-49). */
    public const MinimumAge = 17;

    public const MinimumInterests = 3;

    /**
     * Pasos del perfil obligatorio, en el orden en que la app los pide (T-49).
     *
     * @var list<string>
     */
    public const Steps = ['basics', 'gender', 'studies', 'interests', 'photos', 'bio'];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'gender' => Gender::class,
            'completed_at' => 'datetime',
            'nearby_enabled' => 'boolean',
            'discovery_filters' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Zona declarada: campus o colonia (RF-18).
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /**
     * Pasos que le faltan al perfil, en el orden de `Steps`. Vacío cuando está completo.
     *
     *   basics     nombre y fecha de nacimiento
     *   gender     género
     *   studies    carrera y semestre
     *   interests  al menos 3 intereses
     *   photos     al menos 1 foto
     *   bio        "Sobre mí"
     *
     * @return list<string>
     */
    public function missingSteps(): array
    {
        $this->loadMissing('user');

        $missing = [
            'basics' => blank($this->user?->name) || $this->birthdate === null,
            'gender' => $this->gender === null,
            'studies' => blank($this->career) || $this->semester === null,
            'interests' => $this->interests()->count() < self::MinimumInterests,
            'photos' => ! $this->photos()->exists(),
            'bio' => blank($this->bio),
        ];

        return array_values(array_filter(self::Steps, fn (string $step): bool => $missing[$step]));
    }

    /**
     * Marca el perfil como completo o incompleto según los datos actuales. Se llama después de cada
     * cambio del perfil, sus intereses o sus fotos: si se borra la única foto, vuelve a estar incompleto.
     */
    public function syncCompletion(): void
    {
        $complete = $this->missingSteps() === [];

        if ($complete && $this->completed_at === null) {
            $this->forceFill(['completed_at' => now()])->save();
        } elseif (! $complete && $this->completed_at !== null) {
            $this->forceFill(['completed_at' => null])->save();
        }
    }
}
