<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['match_id', 'sender_id', 'type', 'body', 'attachment_path', 'latitude', 'longitude', 'read_at'])]
#[Hidden(['attachment_path'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(UserMatch::class, 'match_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * URL firmada de 15 minutos para ver la imagen (T-15). Las imágenes viven en un bucket privado: la ruta
     * nunca sale en la API y la URL solo se entrega a los dos del match, que son los únicos que ven el chat.
     */
    public function imageUrl(): ?string
    {
        if ($this->type !== self::TYPE_IMAGE || $this->attachment_path === null) {
            return null;
        }

        return Storage::disk(config('filesystems.chat_images_disk'))
            ->temporaryUrl($this->attachment_path, now()->addMinutes(15));
    }
}
