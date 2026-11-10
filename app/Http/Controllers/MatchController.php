<?php

namespace App\Http\Controllers;

use App\Http\Resources\MatchResource;
use App\Models\UserMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MatchController extends Controller
{
    /**
     * Lista de matches del alumno, del más reciente al más antiguo (RF-07). Se pagina de 20 en 20.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $matches = UserMatch::query()
            ->visibleTo($request->user())
            ->with(UserMatch::userSummaryRelations())
            ->orderByDesc('matches.id')
            ->paginate(20);

        return MatchResource::collection($matches);
    }
}
