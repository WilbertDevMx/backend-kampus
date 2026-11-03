<?php

namespace App\Enums;

/**
 * Estado de aprobación de un grupo (RF-23). Solo los `approved` aparecen en la exploración.
 */
enum GroupStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
