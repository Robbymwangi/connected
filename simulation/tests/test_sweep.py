import statistics
import tempfile
import unittest
from pathlib import Path

from sim.sweep import RESOLVERS, read_summary, run, summarize, table, write_csv
from sim.trace import Scenario

SMALL = {"small": Scenario(classes=3, students=10, criteria=3, assessments=3, cover=0.5, correction=0.2)}


class SweepTest(unittest.TestCase):
    def setUp(self):
        self.rows = run(SMALL, [0.0, 86_400.0], range(3))

    def test_one_row_per_seed_clock_error_and_resolver(self):
        self.assertEqual(len(self.rows), 3 * 2 * len(RESOLVERS))

    def test_the_same_seeds_give_the_same_rows(self):
        self.assertEqual(self.rows, run(SMALL, [0.0, 86_400.0], range(3)))

    def test_the_summary_is_the_mean_of_the_rows(self):
        summary = summarize(self.rows)
        name = RESOLVERS[0].name
        lost = next(s for s in summary if s.resolver == name and s.skew == 0.0 and s.rate == "lost")
        rows = [r for r in self.rows if r.resolver == name and r.skew == 0.0]
        self.assertAlmostEqual(lost.mean, statistics.fmean(r.lost / r.submissions for r in rows))
        self.assertEqual(lost.seeds, 3)

    def test_a_run_with_no_real_conflicts_is_left_out_of_caught(self):
        calm = {"calm": Scenario(classes=2, students=5, criteria=2, assessments=2, cover=0.0, correction=0.0)}
        summary = summarize(run(calm, [0.0], range(2)))
        self.assertFalse(any(s.rate == "caught" for s in summary))
        self.assertTrue(any(s.rate == "lost" for s in summary))

    def test_the_summary_survives_a_round_trip_through_csv(self):
        summary = summarize(self.rows)
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / "summary.csv"
            write_csv(path, summary)
            self.assertEqual(read_summary(path), summary)

    def test_the_table_has_a_row_per_clock_error(self):
        lines = table(summarize(self.rows), "small", "lost").splitlines()
        self.assertEqual(len(lines), 2 + 2)
        self.assertIn("none", lines[2])


@unittest.skipUnless(__import__("importlib").util.find_spec("matplotlib"), "matplotlib is only for the charts")
class PlotsTest(unittest.TestCase):
    def test_a_chart_is_written(self):
        from sim.plots import chart

        summary = summarize(run(SMALL, [0.0, 3600.0], range(2)))
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / "lost.png"
            chart(summary, "small", "lost", path)
            self.assertGreater(path.stat().st_size, 0)
