# Upstream

This directory is a vendored copy of:

- **Project:** zj-58 — CUPS filter for ZJ-58 / ZJ-80 / XP-58 / Epson TM-T20 and other ESC/POS thermal receipt printers
- **Source:** https://github.com/klirichek/zj-58
- **Snapshot date:** 2019-04-29 (zj-58-master)
- **License:** see `LICENSE` (preserved verbatim)
- **Author:** Alexey N. Vinogradov &lt;a.n.vinogradov@gmail.com&gt;

## Why vendored

CUPS filters live in `/usr/lib/cups/filter/` and need a binary compiled against the system's CUPS dev headers. There's no portable pre-built artifact, so the source ships in this repo and is compiled on the target machine by `printer/scripts/install-driver.sh`.

## CPHC-specific use

The Black Copper BC-88AC (80mm USB thermal receipt printer) is ESC/POS-compatible and works with `zj80.ppd` from this driver.

## Modifications

**None.** The C source, PPDs, CMakeLists, and license are byte-for-byte identical to upstream. CPHC integration lives entirely in `../../scripts/`.

If you ever need to refresh from upstream:

```bash
TMP=$(mktemp -d)
git clone --depth=1 https://github.com/klirichek/zj-58 "$TMP/zj-58"
rm -rf printer/driver/zj-58/*
cp -r "$TMP/zj-58/." printer/driver/zj-58/
rm -rf "$TMP"
# Keep this UPSTREAM.md by re-creating it manually after the rsync.
```
