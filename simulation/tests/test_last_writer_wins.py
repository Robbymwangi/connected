import unittest
from dataclasses import replace

from sim.resolvers import LastWriterWins
from sim.score import score
from sim.trace import Scenario, generate
from tests.helpers import sub, trace_of


class LastWriterWinsTest(unittest.TestCase):
    def test_a_correction_with_a_sound_clock_wins_and_nothing_is_lost(self):
        trace = trace_of(sub(0, "a", 5, made=1, received=2), sub(1, "b", 9, made=4, received=5, pulled=3))
        result = score(trace, LastWriterWins())
        self.assertEqual((result.lost, result.reversals), (0, 0))

    def test_a_clock_running_ahead_throws_away_the_newer_correction(self):
        # a's clock says 100; b corrects it at true time 4, having seen it, and loses.
        trace = trace_of(sub(0, "a", 5, made=1, received=2, at=100), sub(1, "b", 9, made=4, received=5, pulled=3))
        result = score(trace, LastWriterWins())
        self.assertEqual((result.lost, result.reversals), (1, 1))

    def test_a_real_conflict_is_settled_silently_and_one_value_is_lost(self):
        trace = trace_of(sub(0, "a", 5, made=1, received=2), sub(1, "b", 9, made=1.5, received=5, pulled=0))
        result = score(trace, LastWriterWins())
        self.assertEqual((result.real, result.caught, result.missed, result.lost), (1, 0, 1, 1))

    def test_equal_values_are_never_lost(self):
        trace = trace_of(sub(0, "a", 5, made=1, received=2, at=100), sub(1, "b", 5, made=4, received=5, pulled=3))
        self.assertEqual(score(trace, LastWriterWins()).lost, 0)

    def test_with_sound_clocks_it_never_reverses_a_correction(self):
        # Knowing a value means pulling it after it was made, so a sound clock orders them.
        result = score(generate(Scenario(cover=0.5, correction=0.2), 11), LastWriterWins())
        self.assertEqual(result.reversals, 0)
        # Every loss is then a missed conflict; one lost value can be missed again by a later
        # arrival that also never saw it, so there can be more misses than losses.
        self.assertGreater(result.lost, 0)
        self.assertLessEqual(result.lost, result.missed)

    def test_with_skewed_clocks_it_reverses_corrections(self):
        result = score(generate(Scenario(cover=0.5, correction=0.2, skew=7 * 86_400), 11), LastWriterWins())
        self.assertGreater(result.reversals, 0)
