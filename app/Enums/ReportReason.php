<?php

namespace App\Enums;

/**
 * Motivos de reporte (RF-14, anteproyecto sección 15.1).
 */
enum ReportReason: string
{
    case Acoso = 'acoso';
    case PerfilFalso = 'perfil_falso';
    case ContenidoInapropiado = 'contenido_inapropiado';
    case Spam = 'spam';
    case Otro = 'otro';
}
