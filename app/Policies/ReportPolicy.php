<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Quién atiende un reporte (T-37, RF-15). Un reporte fuera del alcance responde `404`, para no revelar
 * que existe.
 */
class ReportPolicy
{
    /**
     * El moderador, si el reportado es de su universidad; el administrador, siempre.
     */
    public function review(User $user, Report $report): Response
    {
        return Report::query()->reviewableBy($user)->whereKey($report->getKey())->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
