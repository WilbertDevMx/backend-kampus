<?php

namespace App\Enums;

/**
 * Roles de las cuentas (users.role). El registro público solo crea estudiantes; el staff lo crea un
 * administrador (RF-22). Ver docs/base-de-datos/esquema.md, sección 3.
 */
enum UserRole: string
{
    case Student = 'student';
    case Moderator = 'moderator';
    case Admin = 'admin';

    /** Moderadores y administradores: usan el panel de administración, no las funciones sociales. */
    public function isStaff(): bool
    {
        return $this !== self::Student;
    }
}
