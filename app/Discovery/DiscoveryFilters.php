<?php

namespace App\Discovery;

use Illuminate\Validation\Rule;

/**
 * Filtros de descubrimiento que el alumno guarda en `profiles.discovery_filters` (RF-06).
 *
 * Una lista vacía o una carrera `null` significan "sin filtro": se muestran todas las universidades
 * participantes, cualquier carrera y cualquier interés.
 */
final readonly class DiscoveryFilters
{
    /**
     * @param  list<int>  $universities
     * @param  list<int>  $interests
     */
    public function __construct(
        public array $universities = [],
        public ?string $career = null,
        public array $interests = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $career = trim((string) ($data['career'] ?? ''));

        return new self(
            universities: self::ids($data['universities'] ?? []),
            career: $career === '' ? null : $career,
            interests: self::ids($data['interests'] ?? []),
        );
    }

    /**
     * Reglas de validación. `$prefix` sirve para anidarlas, por ejemplo `discovery_filters.` en el perfil.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'universities' => ['sometimes', 'array', 'max:10'],
            // `bail`: sin él, `exists` consulta aunque el valor no sea entero, y PostgreSQL responde con error.
            $prefix.'universities.*' => [
                'bail',
                'integer',
                'distinct',
                Rule::exists('universities', 'id')->where('is_active', true),
            ],
            $prefix.'career' => ['sometimes', 'nullable', 'string', 'max:120'],
            $prefix.'interests' => ['sometimes', 'array', 'max:20'],
            $prefix.'interests.*' => ['bail', 'integer', 'distinct', 'exists:interests,id'],
        ];
    }

    /**
     * @return array{universities: list<int>, career: string|null, interests: list<int>}
     */
    public function toArray(): array
    {
        return [
            'universities' => $this->universities,
            'career' => $this->career,
            'interests' => $this->interests,
        ];
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $values)));
    }
}
