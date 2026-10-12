<?php

namespace App\Support;

/* The CBC performance levels and their thresholds, as a share of the maximum achieved: the
   one server-side copy of the rule, mirroring frontend/src/lib/grading.ts. The analytics query
   builds its SQL from sqlCase() and sqlRank(), and finalize and the seeder call fromShare(), so a
   result's stored level and the level analytics derives can never disagree.
   docs/spec/analytics.md, docs/spec/workflow.md (Finalize). */
enum PerformanceLevel: string
{
    case EE = 'EE';
    case ME = 'ME';
    case AE = 'AE';
    case BE = 'BE';

    /** The lowest share of the maximum that reaches this level. */
    public function minShare(): float
    {
        return match ($this) {
            self::EE => 0.8,
            self::ME => 0.6,
            self::AE => 0.4,
            self::BE => 0.0,
        };
    }

    /** The CBC wording, as frontend/src/lib/grading.ts has it. */
    public function label(): string
    {
        return match ($this) {
            self::EE => 'Exceeding Expectations',
            self::ME => 'Meeting Expectations',
            self::AE => 'Approaching Expectations',
            self::BE => 'Below Expectations',
        };
    }

    /** Ordinal for movement between levels: BE is 0, EE is 3. */
    public function rank(): int
    {
        return match ($this) {
            self::EE => 3,
            self::ME => 2,
            self::AE => 1,
            self::BE => 0,
        };
    }

    public static function fromShare(int $total, int $max): self
    {
        if ($max <= 0) {
            throw new \InvalidArgumentException('a performance level needs a positive maximum');
        }

        return self::forShare($total / $max);
    }

    /** The level of a share already worked out, such as a term's mean of assessment shares. */
    public static function forShare(float $share): self
    {
        foreach (self::cases() as $level) {
            if ($share >= $level->minShare()) {
                return $level;
            }
        }

        return self::BE;
    }

    /** A SQL case over a share expression, yielding the level's code. A null share falls through to BE, as in PHP's loop. */
    public static function sqlCase(string $share): string
    {
        return self::sqlOver($share, fn (self $level) => "'{$level->value}'");
    }

    /** The same case, yielding rank() instead of the code. */
    public static function sqlRank(string $share): string
    {
        return self::sqlOver($share, fn (self $level) => (string) $level->rank());
    }

    private static function sqlOver(string $share, \Closure $then): string
    {
        $branches = '';

        foreach ([self::EE, self::ME, self::AE] as $level) {
            $branches .= "when ({$share}) >= {$level->minShare()} then {$then($level)} ";
        }

        return "case {$branches}else {$then(self::BE)} end";
    }
}
