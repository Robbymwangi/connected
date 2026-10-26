import bisect
import unittest
from dataclasses import replace

from sim.trace import DAY, Scenario, generate

SMALL = Scenario(classes=3, students=10, criteria=3, assessments=3, cover=0.5, correction=0.2)


class GenerateTest(unittest.TestCase):
    def test_the_same_seed_gives_the_same_term(self):
        self.assertEqual(generate(SMALL, 7).submissions, generate(SMALL, 7).submissions)

    def test_changing_the_skew_changes_only_the_clocks(self):
        calm = generate(SMALL, 7)
        skewed = generate(replace(SMALL, skew=3600), 7)
        strip = lambda t: [replace(s, at=0) for s in t.submissions]
        self.assertEqual(strip(calm), strip(skewed))
        self.assertTrue(all(s.at == s.made for s in calm.submissions))
        self.assertTrue(any(s.at != s.made for s in skewed.submissions))

    def test_a_clock_is_off_by_at_most_the_skew(self):
        trace = generate(replace(SMALL, skew=600), 3)
        for device in trace.devices.values():
            self.assertLessEqual(abs(device.offset), 600)
        for s in trace.submissions:
            self.assertAlmostEqual(s.at - s.made, trace.devices[s.device].offset)

    def test_a_submission_is_received_at_its_device_next_sync_and_knows_the_one_before(self):
        trace = generate(SMALL, 5)
        for s in trace.submissions:
            syncs = trace.devices[s.device].syncs
            i = bisect.bisect_right(syncs, s.made)
            self.assertEqual(s.received, syncs[i])
            self.assertEqual(s.pulled, syncs[i - 1] if i > 0 else float("-inf"))
            self.assertLess(s.pulled, s.made)
            self.assertLess(s.made, s.received)

    def test_submissions_are_in_the_order_the_server_received_them(self):
        received = [s.received for s in generate(SMALL, 5).submissions]
        self.assertEqual(received, sorted(received))

    def test_a_device_sends_one_value_per_cell_per_sync(self):
        trace = generate(replace(SMALL, correction=1.0, offline_mean_days=60), 5)
        keys = [(s.device, s.cell, s.received) for s in trace.submissions]
        self.assertEqual(len(keys), len(set(keys)))

    def test_every_cell_is_entered_by_its_own_teacher(self):
        s = SMALL
        trace = generate(replace(s, cover=0, correction=0), 1)
        self.assertEqual(len(trace.submissions), s.classes * s.students * s.criteria * s.assessments)
        for sub in trace.submissions:
            self.assertEqual(sub.teacher, "t" + sub.cell.split(":")[1][1:])

    def test_every_edit_reaches_the_server_within_the_run(self):
        trace = generate(SMALL, 2)
        end = (SMALL.term_days + SMALL.marking_days + 30) * DAY
        self.assertTrue(all(s.received <= end for s in trace.submissions))
