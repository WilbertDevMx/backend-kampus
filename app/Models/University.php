<?php

namespace App\Models;

use Database\Factories\UniversityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'short_name', 'email_domains', 'is_active'])]
class University extends Model
{
    /** @use HasFactory<UniversityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_domains' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Institución activa a la que pertenece el dominio de un correo, o null si no es institucional (RF-01).
     */
    public static function forEmail(string $email): ?self
    {
        $domain = Str::lower(Str::after(trim($email), '@'));

        if ($domain === '' || ! str_contains($email, '@')) {
            return null;
        }

        return static::query()
            ->where('is_active', true)
            ->whereJsonContains('email_domains', $domain)
            ->first();
    }
}
