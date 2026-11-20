<?php

namespace App\Sms;

use RuntimeException;

/**
 * El proveedor no aceptó el SMS (saldo agotado, número inválido, llaves incorrectas...). El mensaje de la
 * excepción no incluye el texto del SMS, que puede llevar un código.
 */
class SmsDeliveryFailed extends RuntimeException {}
