<?php

namespace App\Models;

use App\Enums\ZoneType;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zona del catálogo: un campus o una colonia (RF-18). Su centro es un punto público (el del campus o el de
 * la colonia), nunca la ubicación de un alumno.
 */
#[Fillable(['name', 'type', 'university_id', 'city', 'center_lat', 'center_lng'])]
class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ZoneType::class,
            'center_lat' => 'float',
            'center_lng' => 'float',
        ];
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }
}
