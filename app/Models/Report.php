<?php

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reporte de un usuario (RF-14). Lo atiende el moderador de la universidad del **reportado**
 * (`university_id`) o un administrador (T-37). `status` y los datos de la revisión no son asignables en masa.
 */
#[Fillable(['reporter_id', 'reported_id', 'match_id', 'reason', 'description', 'university_id'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reported(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(UserMatch::class, 'match_id');
    }

    /**
     * Moderador o administrador que lo revisó (T-37).
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Universidad del reportado, que define quién atiende el reporte.
     *
     * @return BelongsTo<University, $this>
     */
    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function isPending(): bool
    {
        return $this->status === ReportStatus::Pending;
    }

    /**
     * Reportes que `$staff` puede ver y atender (T-37): el moderador, los de su universidad (la del
     * reportado); el administrador, todos; un estudiante, ninguno.
     *
     * @param  Builder<Report>  $query
     */
    public function scopeReviewableBy(Builder $query, User $staff): void
    {
        match ($staff->role) {
            UserRole::Admin => null,
            UserRole::Moderator => $staff->university_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('reports.university_id', $staff->university_id),
            UserRole::Student => $query->whereRaw('1 = 0'),
        };
    }
}
