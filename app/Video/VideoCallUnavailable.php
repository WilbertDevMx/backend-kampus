<?php

namespace App\Video;

use RuntimeException;

/**
 * El proveedor de videollamadas no está configurado o no respondió. La API responde 503 y la app puede
 * seguir con el chat (plan de contingencia del riesgo R4).
 */
final class VideoCallUnavailable extends RuntimeException {}
