import unittest
from dataclasses import replace

from sim.resolvers import ServerVersions, VectorClocks
from sim.score import score
from sim.trace import Scenario, generate
from tests.helpers import sub, trace_of

WEEK = 7 * 86_400
BUSY = Scenario(cover=0.5, correction=0.2)


class ServerVersionsTest(unittest.TestCase):
    def test_a_correction_made_after_pulling_is_applied(self):
        trace = trace_of(sub(0, "a", 5, made=1, received=2), sub(1, "b", 9, made=4, received=5, pulled=3))
        result = score(trace, ServerVersions())
        self.assertEqual((result.caught, result.false_alarm, result.lost), (0, 0, 0))

    def test_a_clock_running_ahead_changes_nothing(self):
        # The case last writer wins gets wrong: no clock is read, so the correction stands.
        trace = trace_of(sub(0, "a", 5, made=1, received=2, at=100), sub(1, "b", 9, made=4, received=5, pulled=3))
        result = score(trace, ServerVersions())
        self.assertEqual((result.lost, result.reversals), (0, 0))

    def test_a_value_edited_without_seeing_the_other_is_a_conflict(self):
        trace = trace_of(sub(0, "a", 5, made=1, received=2), sub(1, "b", 9, made=1.5, received=5, pulled=0))
        result = score(trace, ServerVersions())
        self.assertEqual((result.real, result.caught, result.lost), (1, 1, 0))

    def test_two_offline_creates_of_one_cell_conflict_only_if_they_differ(self):
        differ = trace_of(sub(0, "a", 5, made=1, received=2), sub(1, "b", 9, made=1, received=3))
        agree = trace_of(sub(0, "a", 5, made=1, received=2), sub(1, "b", 5, made=1, received=3))
        self.assertEqual(score(differ, ServerVersions()).caught, 1)
        self.assertEqual(score(agree, ServerVersions()).false_alarm, 0)

    def test_a_repeated_value_does_not_raise_the_version(self):
        # b repeats a's 5 at a stale base: nothing changes. c pulled version 1 before b
        # arrived, and the cell is still at version 1, so c's edit is applied, not a conflict.
        trace = trace_of(
            sub(0, "a", 5, made=1, received=2),
            sub(1, "b", 5, made=1.5, received=4),
            sub(2, "c", 9, made=3.5, received=6, pulled=3),
        )
        resolver = ServerVersions()
        outcomes = [resolver.receive(s).kind for s in trace.submissions]
        self.assertEqual(outcomes, ["applied", "unchanged", "applied"])

    def test_a_correction_of_a_value_typed_twice_is_missed_but_covered(self):
        # b typed a's 10 again without seeing it; c saw a's 10 and corrects it. The truth
        # counts c against b's unseen 10, but c had seen that value, so nothing is lost.
        trace = trace_of(
            sub(0, "a", 10, made=1, received=2),
            sub(1, "b", 10, made=1.5, received=3),
            sub(2, "c", 0, made=4, received=5, pulled=2.5),
        )
        result = score(trace, ServerVersions())
        self.assertEqual((result.real, result.missed, result.covered, result.lost), (1, 1, 1, 0))
        self.assertEqual(score(trace, VectorClocks()).caught, 1)

    def test_an_edit_past_an_open_conflict_is_missed_but_covered(self):
        # a's 5 conflicts with b's 9. c saw b's 9 and not a's 5, and enters 7 at the current
        # version: applied. a's 5 is still in the open conflict, waiting for a person.
        trace = trace_of(
            sub(0, "b", 9, made=2, received=3),
            sub(1, "a", 5, made=1, received=10),
            sub(2, "c", 7, made=6, received=12, pulled=4),
        )
        result = score(trace, ServerVersions())
        self.assertEqual((result.real, result.caught, result.missed, result.covered, result.lost), (2, 1, 1, 1, 0))
        self.assertEqual(score(trace, VectorClocks()).caught, 2)


class ClockIndependenceTest(unittest.TestCase):
    """Neither design reads a clock, so a week of clock error changes nothing at all."""

    def test_neither_loses_anything_or_raises_a_false_alarm(self):
        for resolver in (ServerVersions, VectorClocks):
            with self.subTest(resolver.name):
                result = score(generate(replace(BUSY, skew=WEEK), 11), resolver())
                self.assertEqual((result.lost, result.reversals, result.false_alarm), (0, 0, 0))
                self.assertGreater(result.caught, 0)

    def test_the_score_is_the_same_with_and_without_clock_error(self):
        for resolver in (ServerVersions, VectorClocks):
            with self.subTest(resolver.name):
                calm = score(generate(BUSY, 11), resolver())
                skewed = score(generate(replace(BUSY, skew=WEEK), 11), resolver())
                self.assertEqual(replace(calm, skew=0), replace(skewed, skew=0))

    def test_vector_clocks_catch_every_real_conflict(self):
        result = score(generate(BUSY, 11), VectorClocks())
        self.assertEqual((result.caught, result.missed), (result.real, 0))

    def test_every_conflict_server_versions_miss_is_covered(self):
        result = score(generate(BUSY, 11), ServerVersions())
        self.assertGreater(result.missed, 0)
        self.assertEqual(result.covered, result.missed)

    def test_server_versions_keep_one_number_per_cell(self):
        self.assertEqual(score(generate(BUSY, 11), ServerVersions()).metadata_max, 1)
        self.assertGreater(score(generate(BUSY, 11), VectorClocks()).metadata_max, 1)
