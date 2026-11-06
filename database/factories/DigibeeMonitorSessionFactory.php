<?php

namespace Database\Factories;

use App\Models\DigibeeMonitorSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DigibeeMonitorSession> */
class DigibeeMonitorSessionFactory extends Factory
{
    protected $model = DigibeeMonitorSession::class;

    public function definition(): array
    {
        $expiresAt = now()->addHours(20);

        return [
            'authorization' => 'Bearer ' . self::jwt($expiresAt->getTimestamp()),
            'apikey'        => 'test-apikey',
            'expires_at'    => $expiresAt,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }

    /** An unsigned JWT carrying `exp` and `realm` — all MonitorSession ever reads from one. */
    public static function jwt(int $exp, ?string $realm = null): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'none'])
            . '.' . $encode(['exp' => $exp, 'realm' => $realm ?? config('services.digibee.monitor.realm')])
            . '.signature';
    }
}
