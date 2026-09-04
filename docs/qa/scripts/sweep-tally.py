#!/usr/bin/env python3
"""Recompute DEEP-SWEEP-2026-09-01.md's tallies FROM ITS OWN ROWS.

The file's preamble says a status column nobody maintains is worse than none, because it reads as
current — and the same is true of the counts beside it. Every per-section subtotal in the first
version was a snapshot taken when that section was written, so they were already wrong by the time
the third row closed: the header said 193 open while the table held 195, and the money section
claimed 11 high when 7 were left.

Run this after changing any row's status. It never edits a row — only the ``*N open — …*`` line under
each section heading and the ``Where this stands`` line at the top.

    python3 docs/qa/scripts/sweep-tally.py [--check]

``--check`` exits non-zero instead of writing, so it can be used as a gate.
"""
import re
from datetime import date
import sys
from pathlib import Path

DOC = Path(__file__).resolve().parents[1] / 'DEEP-SWEEP-2026-09-01.md'
SEVERITIES = ('critical', 'high', 'medium', 'low')


def retally(text: str) -> str:
    lines = text.split('\n')
    section, rows, order = None, {}, []

    for line in lines:
        if line.startswith('### ') and 'Where this stands' not in line:
            section = line
            order.append(section)
            rows[section] = []
        elif re.match(r'^\| \*\*SW-', line) and section:
            rows[section].append(line)

    total_open = total_closed = 0

    for section in order:
        # | **SW-009** | open | high | M | what | where |  →  [2] status, [3] severity
        # `startswith`, not `==`. A status cell reading `open — **needs a decision**` is OPEN, and an
        # exact match counted it as CLOSED — silently, which is the one failure mode a tally has.
        # (SW-050 carried exactly that wording for two commits.)
        open_rows = [r for r in rows[section] if r.split('|')[2].strip().startswith('open')]
        total_open += len(open_rows)
        total_closed += len(rows[section]) - len(open_rows)

        found = [r.split('|')[3].strip() for r in open_rows]
        breakdown = ', '.join(f'{found.count(s)} {s}' for s in SEVERITIES if found.count(s))
        # A cleared section reads `*0 open.*` — the old form emitted a dangling `— .` that looks
        # like a truncated sentence rather than a finished one.
        subtotal = f'*{len(open_rows)} open — {breakdown}.*' if breakdown else '*0 open.*'

        start = lines.index(section)
        for i in range(start + 1, min(start + 5, len(lines))):
            if lines[i].startswith('*') and ' open — ' in lines[i]:
                lines[i] = subtotal
                break

    for i, line in enumerate(lines):
        if line.startswith('> ### Where this stands'):
            # The date is REGENERATED, not preserved. Carrying the old one forward is how a header
            # comes to say "updated 2026-09-03" over a count taken today — a stale date under a
            # fresh number is worse than either alone, because it makes the number look checked.
            lines[i] = (f'> ### Where this stands — {total_closed} closed, {total_open} open '
                        f'(updated {date.today().isoformat()})')

    return '\n'.join(lines)


if __name__ == '__main__':
    before = DOC.read_text()
    after = retally(before)

    if '--check' in sys.argv:
        if before != after:
            sys.exit('Tallies are stale — run: python3 docs/qa/scripts/sweep-tally.py')
        print('Tallies agree with the rows.')
    else:
        DOC.write_text(after)
        print('Up to date.' if before == after else 'Retallied.')
