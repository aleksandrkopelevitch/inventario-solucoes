<?php

namespace Database\Factories;

use App\Enums\DigibeeLogReportStatus;
use App\Models\DigibeeLogReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DigibeeLogReport> */
class DigibeeLogReportFactory extends Factory
{
    protected $model = DigibeeLogReport::class;

    public function definition(): array
    {
        return [
            'source'      => 'screen',
            'status'      => DigibeeLogReportStatus::Pending,
            'environment' => 'prod',
            'pipeline'    => 'evt-send-estoque-vtex',
            'term'        => null,
            'where'       => 'both',
            'from_at'     => now()->subHour(),
            'to_at'       => now(),
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => ['status' => DigibeeLogReportStatus::Done, 'executions_found' => 0, 'executions_opened' => 0]);
    }
}
