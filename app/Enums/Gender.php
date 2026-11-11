<?php

namespace App\Enums;

/**
 * Género del estudiante (T-49). Solo se muestra en el perfil: no filtra el descubrimiento.
 */
enum Gender: string
{
    case Mujer = 'mujer';
    case Hombre = 'hombre';
    case NoBinario = 'no_binario';
    case PrefieroNoDecir = 'prefiero_no_decir';
}
