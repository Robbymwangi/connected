from sim.trace import Scenario, Submission, Trace

NEVER = float("-inf")


def sub(id: int, device: str, value: int, made: float, received: float, pulled: float = NEVER, at: float | None = None, cell: str = "x") -> Submission:
    """A hand-made submission; `at` defaults to the true time, a correct clock."""
    return Submission(id, device, device, cell, value, made, made if at is None else at, received, pulled)


def trace_of(*subs: Submission) -> Trace:
    return Trace(Scenario(), 0, {}, tuple(subs))
