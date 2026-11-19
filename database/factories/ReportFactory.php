<?php

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Un estudiante reporta a otro de la misma universidad. El reporte va a la universidad del reportado,
     * como lo hace `POST /api/reports`. `university_id` va primero porque los demás campos lo usan.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'university_id' => University::factory(),
            'reporter_id' => fn (array $attributes) => User::factory()
                ->student(University::query()->findOrFail($attributes['university_id'])),
            'reported_id' => fn (array $attributes) => User::factory()
                ->student(University::query()->findOrFail($attributes['university_id'])),
            'reason' => ReportReason::Spam,
            'description' => null,
        ];
    }

    /**
     * Reporte contra `$reported`, en su universidad.
     */
    public function against(User $reported): static
    {
        return $this->state(fn (array $attributes) => [
            'reported_id' => $reported->getKey(),
            'university_id' => $reported->university_id,
        ]);
    }

    public function by(User $reporter): static
    {
        return $this->state(fn (array $attributes) => ['reporter_id' => $reporter->getKey()]);
    }

    /**
     * Reporte ya atendido. `status` no es asignable en masa, por eso se fija después de crearlo.
     */
    public function reviewed(?User $reviewer = null): static
    {
        return $this->withStatus(ReportStatus::Reviewed, $reviewer);
    }

    public function dismissed(?User $reviewer = null): static
    {
        return $this->withStatus(ReportStatus::Dismissed, $reviewer);
    }

    private function withStatus(ReportStatus $status, ?User $reviewer): static
    {
        return $this->afterMaking(fn (Report $report) => $report->forceFill([
            'status' => $status,
            'reviewed_by' => $reviewer?->getKey(),
            'reviewed_at' => now(),
        ]));
    }
}
