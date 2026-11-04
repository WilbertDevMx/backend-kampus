<?php

namespace App\Enums;

/**
 * Estado de un reporte en moderación (RF-15). Lo cambia el staff en T-37.
 */
enum ReportStatus: string
{
    case Pending = 'pending';
    case Reviewed = 'reviewed';
    case Dismissed = 'dismissed';
}
