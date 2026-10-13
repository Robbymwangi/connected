{{-- A student's report card for one term (ADR 0012; docs/spec/workflow.md, Report cards). Rendered by
     Dompdf from App\Support\Reports\ReportCard, which carries levels only: no percentage, position,
     rank, or class size. Inline styles and a bundled font, since Dompdf fetches nothing remote. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $student }}, Term {{ $term }} {{ $year }}</title>
<style>
    @page { margin: 28mm 18mm 22mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #2b2522; }
    h1 { font-size: 15pt; margin: 0; }
    h2 { font-size: 11pt; margin: 18pt 0 6pt; color: #c1440e; }
    .meta { margin-top: 4pt; color: #6b5f58; }
    .rule { border-bottom: 2pt solid #c1440e; margin: 10pt 0 4pt; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 5pt 6pt; border-bottom: 0.5pt solid #ddd3cc; }
    th { font-size: 9pt; color: #6b5f58; }
    td.level { width: 18%; text-align: center; }
    th.level { text-align: center; }
    .remark { border: 0.5pt solid #ddd3cc; padding: 8pt 10pt; line-height: 1.45; }
    .key { font-size: 8.5pt; color: #6b5f58; margin-top: 14pt; }
</style>
</head>
<body>
    <h1>{{ $school }}</h1>
    <div class="meta">Report card: Term {{ $term }}, {{ $year }}</div>
    <div class="rule"></div>
    <table>
        <tr><th>Student</th><td>{{ $student }}</td><th>Class</th><td>{{ $stream }}</td></tr>
    </table>

    <h2>Performance this year</h2>
    <table>
        <thead>
            <tr><th>Subject</th>@foreach ([1, 2, 3] as $t)<th class="level">Term {{ $t }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @forelse ($subjects as $row)
                <tr>
                    <td>{{ $row['subject'] }}</td>
                    @foreach ([1, 2, 3] as $t)
                        <td class="level">{{ $row['terms'][$t]?->value ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="4">No assessed subjects this year.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if (count($history) > 0)
        <h2>Earlier years</h2>
        <table>
            <thead><tr><th>Year</th><th>Class</th><th>Subjects</th></tr></thead>
            <tbody>
                @foreach ($history as $past)
                    <tr>
                        <td>{{ $past['year'] }}</td>
                        <td>{{ $past['stream'] ?? '' }}</td>
                        <td>{{ collect($past['subjects'])->map(fn ($s) => $s['subject'].' '.$s['level']->value)->implode(', ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Class teacher's remark</h2>
    <div class="remark">{{ $remark }}</div>

    <div class="key">
        @foreach ($levels as $level){{ $level->value }}: {{ $level->label() }}@if (! $loop->last) &middot; @endif @endforeach
    </div>
</body>
</html>
