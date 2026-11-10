<?php

namespace App\Enums;

/**
 * Rol dentro de un grupo (RF-12): el `admin` edita el grupo y publica actividades.
 */
enum GroupMemberRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}
