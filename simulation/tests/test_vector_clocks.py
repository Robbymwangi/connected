import unittest

from sim.resolvers import VectorClocks
from tests.helpers import sub, trace_of


def kinds(*subs):
    resolver = VectorClocks()
    return [resolver.receive(s).kind for s in trace_of(*subs).submissions]


class VectorClocksTest(unittest.TestCase):
    def test_an_edit_that_saw_the_value_replaces_it(self):
        self.assertEqual(kinds(sub(0, "a", 5, made=1, received=2), sub(1, "b", 9, made=4, received=5, pulled=3)), ["applied", "applied"])

    def test_concurrent_values_that_differ_conflict(self):
        self.assertEqual(kinds(sub(0, "a", 5, made=1, received=2), sub(1, "b", 9, made=1.5, received=5, pulled=0)), ["applied", "conflict"])

    def test_concurrent_values_that_agree_do_not(self):
        self.assertEqual(kinds(sub(0, "a", 5, made=1, received=2), sub(1, "b", 5, made=1.5, received=5, pulled=0)), ["applied", "unchanged"])

    def test_an_edit_that_saw_both_sides_of_a_conflict_settles_it(self):
        self.assertEqual(
            kinds(
                sub(0, "a", 5, made=1, received=2),
                sub(1, "b", 9, made=1.5, received=5, pulled=0),
                sub(2, "c", 7, made=7, received=8, pulled=6),
            ),
            ["applied", "conflict", "applied"],
        )

    def test_a_device_counts_its_own_edits(self):
        resolver = VectorClocks()
        for s in (sub(0, "a", 5, made=1, received=2), sub(1, "a", 6, made=3, received=4, pulled=2)):
            resolver.receive(s)
        self.assertEqual(resolver.metadata(), [1])
        self.assertEqual(resolver.siblings["x"][0][0], {"a": 2})
