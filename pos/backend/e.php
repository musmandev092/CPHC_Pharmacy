<?php
/*
 * XSS-safe echo helper.  MANDATORY at every echo of any value that comes
 * from a database or a user.  The CI lint forbids bare `<?= $...` — all
 * dynamic output must use `<?= e($...) ?>`.
 *
 * Three reasons for a global function:
 *   1. Short enough that no one is tempted to skip it.
 *   2. Grep-able: `grep -RnE '<\?=\s*\$' src/pages src/templates` should be
 *      EMPTY.  Anything that pops up is a missing escape.
 *   3. Centralised flag set (ENT_QUOTES | ENT_HTML5, UTF-8) — fix once if
 *      it ever needs to change.
 */

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_array($value) || is_object($value)) {
        // Never let an array/object render — it would leak structure.
        return '[invalid]';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Safe URL-encode for path/query segments. */
function u(mixed $value): string
{
    return rawurlencode((string) ($value ?? ''));
}
