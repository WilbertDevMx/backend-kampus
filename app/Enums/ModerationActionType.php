<?php

namespace App\Enums;

/**
 * Acciones que se registran en la bitácora `moderation_actions` (RF-15, RF-22, RF-23).
 * Los valores son los del diccionario de datos (`docs/base-de-datos/esquema.md`).
 */
enum ModerationActionType: string
{
    case SuspendUser = 'suspend_user';
    case ReactivateUser = 'reactivate_user';
    case DeleteUser = 'delete_user';
    case ApproveGroup = 'approve_group';
    case RejectGroup = 'reject_group';
    case CreateStaff = 'create_staff';
    case DeactivateStaff = 'deactivate_staff';
    case ResolveReport = 'resolve_report';
    case DismissReport = 'dismiss_report';
}
