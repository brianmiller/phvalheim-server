#!/bin/bash
# Applies the position-preserving redraw to BOTH mod pickers.
#
# new_world.php and edit_world.php carry the same picker. Patching them by hand is how they
# drift apart, so the edit is scripted and asserted on both.
set -euo pipefail
cd "$(dirname "$0")/.."

for f in container/nginx/www/admin/new_world.php container/nginx/www/admin/edit_world.php; do
    python3 - "$f" <<'PY'
import sys, re
path = sys.argv[1]
src = open(path).read()

old = """				if (activeTable && allTable) {
					// Reuse existing DataTables — avoids expensive destroy/recreate
					activeTable.clear().rows.add(activeRows).draw();
					allTable.clear().rows.add(allRows).draw();"""

new = """				if (activeTable && allTable) {
					// Reuse existing DataTables — avoids expensive destroy/recreate
					redrawInPlace(activeTable, activeRows);
					redrawInPlace(allTable, allRows);"""

if old not in src:
    if 'redrawInPlace(activeTable' in src:
        print(f"  {path}: already patched")
        sys.exit(0)
    sys.exit(f"FAILED: redraw block not found in {path}")
src = src.replace(old, new, 1)

# The helper goes immediately before rebuildTables(), which is its only caller.
anchor = """			// Rebuild both tables from checkedSet state
			function rebuildTables() {"""
helper = """			// Redraw a table's rows WITHOUT moving the operator.
			//
			// Every checkbox toggle rebuilds both tables from checkedSet, because a selection
			// changes the badges and ordering of other rows. That rebuild must not also throw
			// away where the operator was: with 11,600+ mods, being sent back to the top of
			// the list after every click makes selecting several mods genuinely painful.
			//
			// Two separate things have to be preserved, and each is lost by a different
			// mechanism:
			//   - draw(false) keeps the current PAGE. A bare draw() is draw(true), which
			//     resets paging to page 1 -- that is what sent the list back to the start.
			//   - scrollTop of the scroll body is reset by replacing the rows even when the
			//     page is retained, because DataTables rebuilds the tbody. So it is captured
			//     and restored around the draw.
			function redrawInPlace(table, rows) {
				var body = $(table.table().container()).find('.dataTables_scrollBody');
				var scrollTop = body.scrollTop();
				table.clear().rows.add(rows).draw(false);
				body.scrollTop(scrollTop);
			}

			// Rebuild both tables from checkedSet state
			function rebuildTables() {"""

if anchor not in src:
    sys.exit(f"FAILED: rebuildTables anchor not found in {path}")
src = src.replace(anchor, helper, 1)

open(path, 'w').write(src)
print(f"  {path}: patched")
PY
done

echo
echo "verifying both pages:"
for f in container/nginx/www/admin/new_world.php container/nginx/www/admin/edit_world.php; do
    h=$(grep -c "function redrawInPlace" "$f")
    c=$(grep -c "redrawInPlace(activeTable\|redrawInPlace(allTable" "$f")
    b=$(grep -c "rows.add(activeRows).draw();\|rows.add(allRows).draw();" "$f" || true)
    echo "  $(basename "$f"): helper=$h calls=$c bare-draw-left=$b (want 1 / 2 / 0)"
    [ "$h" = "1" ] && [ "$c" = "2" ] && [ "$b" = "0" ] || { echo "  MISMATCH"; exit 1; }
    php -l "$f" >/dev/null || exit 1
done
echo "OK - both pickers patched and lint-clean"
