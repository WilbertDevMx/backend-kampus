<?php

namespace App\Enums;

/**
 * Sobre qué se hizo una acción de la bitácora (`moderation_actions.target_type`).
 */
enum ModerationTarget: string
{
    case User = 'user';
    case Group = 'group';
    case Report = 'report';
}
