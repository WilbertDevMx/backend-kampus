<?php

namespace Tests\Feature\Discovery;

use App\Models\Interest;
use App\Models\Profile;
use App\Models\University;
use App\Models\User;

trait CreatesStudents
{
    /**
     * Estudiante verificado con perfil, que ya puede aparecer en el descubrimiento.
     *
     * @param  array<string, mixed>  $profile
     * @param  list<Interest>  $interests
     */
    private function studentWithProfile(University $university, array $profile = [], array $interests = []): User
    {
        $user = User::factory()->student($university)->create();
        $created = Profile::factory()->for($user)->create($profile);

        if ($interests !== []) {
            $created->interests()->attach(collect($interests)->pluck('id'));
        }

        return $user;
    }
}
