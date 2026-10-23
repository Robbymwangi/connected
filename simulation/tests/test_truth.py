import unittest

from sim.truth import real_conflicts
from tests.helpers import sub, trace_of


class RealConflictsTest(unittest.TestCase):
    def test_an_edit_made_after_pulling_the_other_is_a_correction_not_a_conflict(self):
        trace = trace_of(sub(0, "a", 5, made=1, received=2), sub(1, "b", 9, made=4, received=5, pulled=3))
        self.assertEqual(real_conflicts(trace), set())

    def test_two_values_neither_device_saw_are_a_conflict(self):
        trace = trace_of(sub(0, "a", 5, made=1, received=2), sub(1, "b", 9, made=1.5, received=5, pulled=0))
        self.assertEqual(real_conflicts(trace), {1})

    def test_two_people_entering_the_same_value_unseen_agree(self):
        trace = trace_of(sub(0, "a", 5, made=1, received=2), sub(1, "b", 5, made=1.5, received=5, pulled=0))
        self.assertEqual(real_conflicts(trace), set())

    def test_a_value_stays_live_until_someone_who_saw_it_replaces_it(self):
        # a never saw b's 9; c saw b's 9 but not a's 5, so c's 9 still disagrees with a live 5.
        trace = trace_of(
            sub(0, "b", 9, made=2, received=3),
            sub(1, "a", 5, made=1, received=10),
            sub(2, "c", 9, made=6, received=12, pulled=4),
        )
        self.assertEqual(real_conflicts(trace), {1, 2})

    def test_other_cells_never_conflict(self):
        trace = trace_of(sub(0, "a", 5, made=1, received=2), sub(1, "b", 9, made=1.5, received=5, cell="y"))
        self.assertEqual(real_conflicts(trace), set())
