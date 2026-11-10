<?php

namespace App\Discovery;

use App\Discovery\Ranking\DiscoveryRanking;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Arma la consulta del descubrimiento paso a paso (patrón Builder).
 *
 * Siempre aplica las reglas de RF-05: solo estudiantes disponibles (`User::discoverableBy()`) y
 * nunca un perfil que `$viewer` ya evaluó. Encima se combinan los filtros opcionales de RF-06 y el
 * criterio de orden, sin condicionales anidados.
 */
final class DiscoveryQuery
{
    /** @var list<int> */
    private array $universityIds = [];

    private ?string $career = null;

    /** @var list<int> */
    private array $interestIds = [];

    private ?DiscoveryRanking $ranking = null;

    /** @var list<string>|null */
    private ?array $cellPrefixes = null;

    private function __construct(private readonly User $viewer) {}

    public static function for(User $viewer): self
    {
        return new self($viewer);
    }

    public function withFilters(DiscoveryFilters $filters): self
    {
        return $this->inUniversities($filters->universities)
            ->withCareer($filters->career)
            ->withAnyInterest($filters->interests);
    }

    /**
     * @param  list<int>  $universityIds  Vacío: todas las universidades participantes.
     */
    public function inUniversities(array $universityIds): self
    {
        $this->universityIds = $universityIds;

        return $this;
    }

    /**
     * Coincidencia parcial y sin distinguir mayúsculas, porque la carrera es texto libre.
     */
    public function withCareer(?string $career): self
    {
        $this->career = $career;

        return $this;
    }

    /**
     * @param  list<int>  $interestIds  Basta con que el perfil tenga uno de ellos.
     */
    public function withAnyInterest(array $interestIds): self
    {
        $this->interestIds = $interestIds;

        return $this;
    }

    /**
     * Vista "Cerca" (T-35): solo quien tiene "Cerca de ti" activo y su celda empieza con alguno de los
     * prefijos de geohash (la zona del que consulta y sus vecinas).
     *
     * @param  list<string>  $prefixes
     */
    public function inCells(array $prefixes): self
    {
        $this->cellPrefixes = $prefixes;

        return $this;
    }

    public function rankedBy(DiscoveryRanking $ranking): self
    {
        $this->ranking = $ranking;

        return $this;
    }

    /**
     * @return Builder<User>
     */
    public function toQuery(): Builder
    {
        $query = User::query()
            ->select('users.*')
            ->addSelect(['shared_interests' => $this->sharedInterestsCount()])
            ->discoverableBy($this->viewer)
            ->whereNotExists(fn (QueryBuilder $swipes) => $swipes->select(DB::raw(1))
                ->from('swipes')
                ->where('swipes.swiper_id', $this->viewer->getKey())
                ->whereColumn('swipes.swiped_id', 'users.id'));

        if ($this->universityIds !== []) {
            $query->whereIn('users.university_id', $this->universityIds);
        }

        if ($this->career !== null) {
            $query->whereHas('profile', fn (Builder $profile) => $profile->whereRaw(
                "LOWER(profiles.career) LIKE ? ESCAPE '\\'",
                ['%'.addcslashes(mb_strtolower($this->career), '%_\\').'%'],
            ));
        }

        if ($this->interestIds !== []) {
            $query->whereHas('profile.interests', fn (Builder $interests) => $interests
                ->whereIn('interests.id', $this->interestIds));
        }

        if ($this->cellPrefixes !== null) {
            $query->whereHas('profile', fn (Builder $profile) => $profile->where('profiles.nearby_enabled', true))
                ->whereHas('location', fn (Builder $location) => $location->where(
                    fn (Builder $cells) => collect($this->cellPrefixes)->each(
                        fn (string $prefix) => $cells->orWhere('user_locations.geohash', 'like', $prefix.'%'),
                    ),
                ));
        }

        $this->ranking?->apply($query, $this->viewer);

        return $query;
    }

    /**
     * Siguientes `$limit` perfiles, con todo lo que muestra la tarjeta ya cargado (sin consultas N+1).
     *
     * @param  list<string>  $extraRelations  Relaciones que necesita otra vista, como "Cerca".
     * @return Collection<int, User>
     */
    public function take(int $limit, array $extraRelations = []): Collection
    {
        return $this->toQuery()
            ->with([
                'university',
                'profile.interests',
                'profile.photos' => fn ($photos) => $photos->orderBy('position'),
                ...$extraRelations,
            ])
            ->limit($limit)
            ->get();
    }

    /**
     * Cuántos intereses comparte cada candidato con `$viewer`.
     */
    private function sharedInterestsCount(): QueryBuilder
    {
        $viewerInterestIds = $this->viewer->profile?->interests()->pluck('interests.id')->all() ?? [];

        return DB::table('interest_profile')
            ->join('profiles', 'profiles.id', '=', 'interest_profile.profile_id')
            ->whereColumn('profiles.user_id', 'users.id')
            ->whereIn('interest_profile.interest_id', $viewerInterestIds)
            ->selectRaw('COUNT(*)');
    }
}
