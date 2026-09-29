<?php

namespace Database\Seeders\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/* One fixed seed for every random draw the seeder makes. "Everything
   downstream is tested against it" (#42): a non-deterministic seeder would
   change which students decline, what the pass rate is, and so on, on
   every run, so nothing built on top of it (including
   SeederPlausibilityTest itself) could assert a stable ground truth. */
class SeededRandom
{
    private const SEED = 20260929;

    private Randomizer $randomizer;

    public function __construct()
    {
        $this->randomizer = new Randomizer(new Mt19937(self::SEED));
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    public function float(): float
    {
        return $this->randomizer->nextFloat();
    }

    public function bool(float $probability): bool
    {
        return $this->float() < $probability;
    }

    /** Box-Muller: a standard normal draw, scaled to the given mean and sd. */
    public function normal(float $mean, float $sd): float
    {
        $u1 = max($this->float(), PHP_FLOAT_EPSILON);
        $u2 = $this->float();

        $z = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);

        return $mean + $z * $sd;
    }

    /** @param array<int, mixed> $items */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    public function shuffled(array $items): array
    {
        return $this->randomizer->shuffleArray($items);
    }
}
