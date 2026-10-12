<?php

namespace App\Models;

use Database\Factories\InterestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name'])]
class Interest extends Model
{
    /** @use HasFactory<InterestFactory> */
    use HasFactory;

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class)->withTimestamps();
    }
}
