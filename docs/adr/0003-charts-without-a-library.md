# 3. Charts are drawn with our own SVG components, not a charting library

Date: 2026-09-17

## Status

Accepted.

## Context

The Figma export draws every chart with `recharts`: the student trend on the Classes
screen, the criterion breakdown on the assessment report, and five panels on Reports.
What those charts need is small and fixed: bar charts (some with two series, one a
histogram), line charts with up to two series, a reference line, and a hover tooltip.
No stacking, pies, zoom, brushing, or dense data; every chart is a handful of
categories.

The application is an offline-first PWA on low-end devices, precached in full on
first install, so bundle weight is paid by every user on a slow link. Colour must
come from the semantic tokens in both themes. And the developers must be able to
explain every part of the system at an oral examination.

Sizes measured on 16 September 2026 (gzipped, current versions): recharts 148 KB with
11 dependencies; Chart.js 67 KB (canvas, styled by options rather than CSS); uPlot
21 KB (canvas, built for dense time series); Chartist 19 KB (SVG styled by CSS, no
built-in tooltip); visx roughly 40 to 50 KB for the primitives we would compose;
Frappe Charts 19 KB but with no release since 2022.

## Decision

Charts are React components rendering inline SVG, in `components/charts/`: a scale
helper, `LineChart`, `BarChart`, and a shared tooltip. Colour comes from the chart
tokens; text wears text tokens; marks are thin and grids recessive. The scale
arithmetic is a pure module with tests.

No charting library is added. Chartist was the closest alternative; it would have
saved the axis and scale arithmetic, which is the easy part, and not the tooltip or
the styling, which are the parts that must be right.

## Consequences

- Zero added bundle weight; dark mode is free because the components use the same
  tokens as everything else.
- Every chart type the product needs is written once here; a chart type outside the
  set above (stacking, zoom, dense series) is a reason to revisit this decision,
  not to extend these components indefinitely.
- Tooltips and keyboard access are ours to get right, and are reviewed as such.
- If the set of charts grows beyond what this covers, the fallback is Chartist for
  its size and CSS styling, recorded in a later ADR.
