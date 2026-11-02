<?php

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['profile_id', 'path', 'position'])]
class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * URL firmada de 15 minutos: las fotos viven en un bucket privado (RNF-03).
     */
    public function temporaryUrl(): string
    {
        return Storage::disk(config('filesystems.profile_photos_disk'))
            ->temporaryUrl($this->path, now()->addMinutes(15));
    }
}
