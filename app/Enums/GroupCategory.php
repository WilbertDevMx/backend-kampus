<?php

namespace App\Enums;

/**
 * Categorías de los grupos (RF-11, anteproyecto sección 15.1).
 */
enum GroupCategory: string
{
    case Deportivo = 'deportivo';
    case Cultural = 'cultural';
    case Academico = 'academico';
    case Social = 'social';
}
