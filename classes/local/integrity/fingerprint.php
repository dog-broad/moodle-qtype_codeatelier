<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace qtype_codeatelier\local\integrity;

defined('MOODLE_INTERNAL') || die();

/**
 * A structural code fingerprint, for similarity comparison.
 *
 * This is the published MOSS algorithm (Schleimer, Wilkerson & Aiken, 2003): the
 * source is normalized so that identifier names, numeric and string literals, and
 * formatting are erased while control-flow structure is kept; the normalized token
 * stream is cut into overlapping k-grams; each k-gram is hashed; and a position-robust
 * subset of those hashes is selected by winnowing. Two submissions are then compared by
 * the Jaccard overlap of their fingerprint sets (see {@see cohort_analyser}).
 *
 * Normalizing identifiers to a single token is what defeats the cheap disguises —
 * renaming variables and reformatting — that a plain text diff misses. The kept-token
 * set is the vocabulary a student does not get to rename: language keywords plus common
 * built-in / standard-library names (a copier cannot rename `enumerate`, so keeping it
 * both preserves the copy match and separates independent solutions that use different
 * built-ins). The names the student chose collapse to "V". A single cross-language union
 * is safe because similarity is only ever computed within one language.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fingerprint {
    /** @var int k-gram size (tokens per shingle). */
    const K = 5;

    /** @var int winnowing window (hashes per window; the window minimum is kept). */
    const W = 4;

    /**
     * @var string[] tokens kept verbatim (a student cannot rename these); every other
     * identifier becomes "V". Control-flow keywords plus the built-in / library names
     * shared across the supported languages.
     *
     * The built-ins here are deliberately only those implausible as a *variable* name —
     * `enumerate`, `println`, `splitlines`. Words that are common built-ins yet also
     * common variable names (`index`, `count`, `sum`, `min`, `max`, `size`, `length`,
     * `find`, `keys`, `map`, …) are excluded on purpose: keeping them would defeat the
     * identifier-renaming defence for any student who uses one as a variable, which is
     * exactly the disguise the fingerprint exists to catch.
     */
    const KEYWORDS = [
        // Control flow and declarations.
        'if', 'else', 'elif', 'for', 'while', 'do', 'switch', 'case', 'default',
        'break', 'continue', 'return', 'yield', 'def', 'function', 'func', 'fn',
        'lambda', 'class', 'struct', 'interface', 'enum', 'try', 'catch', 'except',
        'finally', 'throw', 'raise', 'new', 'in', 'is', 'and', 'or', 'not',
        // SQL.
        'select', 'from', 'where', 'join', 'group', 'order', 'having', 'union',
        // Built-ins / library calls that are never used as variable names.
        'print', 'println', 'printf', 'sprintf', 'scanf', 'range', 'enumerate',
        'sorted', 'reversed', 'append', 'splitlines', 'startswith', 'endswith',
        'isinstance', 'parseint', 'cout', 'cin', 'endl', 'malloc',
    ];

    /**
     * Normalize source into a structural token stream.
     *
     * Comments and string literals are stripped, numbers become "N", string bodies
     * "S", recognized keywords are kept (lowercased, so SQL's case-insensitivity and
     * stylistic casing don't create spurious differences), every other identifier
     * becomes "V", and operators/punctuation are kept verbatim.
     *
     * @param string $src the raw submission
     * @return string[] the normalized tokens
     */
    public static function tokens(string $src): array {
        // Strip comments (C/JS block + line, Python/shell line, SQL line).
        $src = preg_replace('!/\*.*?\*/!s', ' ', $src);
        $src = preg_replace('!//[^\n]*!', ' ', $src);
        $src = preg_replace('!#[^\n]*!', ' ', $src);
        $src = preg_replace('!--[^\n]*!', ' ', $src);
        // Replace string literals with a single marker so copied text can't dominate.
        $src = preg_replace('!"(\\\\.|[^"\\\\])*"!', ' "S" ', $src);
        $src = preg_replace("!'(\\\\.|[^'\\\\])*'!", ' "S" ', $src);
        // Tokenize into words, numbers, or single non-space symbols.
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*|[0-9]+(?:\.[0-9]+)?|[^\sA-Za-z0-9_]/', $src, $m);
        $keywords = array_flip(self::KEYWORDS);
        $out = [];
        foreach ($m[0] as $tok) {
            if ($tok === '"S"') {
                $out[] = 'S';
            } else if (preg_match('/^[0-9]/', $tok)) {
                $out[] = 'N';
            } else if (preg_match('/^[A-Za-z_]/', $tok)) {
                $lc = strtolower($tok);
                $out[] = isset($keywords[$lc]) ? $lc : 'V';
            } else {
                $out[] = $tok;
            }
        }
        return $out;
    }

    /**
     * The number of normalized tokens — the complexity measure the floor uses.
     *
     * @param string $src
     * @return int
     */
    public static function token_count(string $src): int {
        return count(self::tokens($src));
    }

    /**
     * The winnowed fingerprint set for one submission.
     *
     * @param string $src
     * @return int[] the selected k-gram hashes (a set; order is not meaningful)
     */
    public static function of(string $src): array {
        return self::winnow(self::kgrams(self::tokens($src)));
    }

    /**
     * Hash every k-gram of the token stream.
     *
     * @param string[] $tokens
     * @return int[] one 32-bit hash per k-gram (short streams use a smaller k)
     */
    private static function kgrams(array $tokens): array {
        $hashes = [];
        $n = count($tokens);
        $k = $n < self::K ? max(1, $n) : self::K;
        for ($i = 0; $i + $k <= $n; $i++) {
            $hashes[] = crc32(implode(' ', array_slice($tokens, $i, $k))) & 0xffffffff;
        }
        return $hashes;
    }

    /**
     * Winnow a hash sequence: in every window of W, keep the minimum. This selects a
     * robust, position-independent subset so that local edits and reordering change the
     * fingerprint only locally.
     *
     * @param int[] $hashes
     * @return int[] the fingerprint set (unique selected hashes)
     */
    private static function winnow(array $hashes): array {
        $n = count($hashes);
        if ($n === 0) {
            return [];
        }
        if ($n <= self::W) {
            return array_values(array_unique(array_merge([min($hashes)], $hashes)));
        }
        $fp = [];
        for ($i = 0; $i + self::W <= $n; $i++) {
            $fp[min(array_slice($hashes, $i, self::W))] = true;
        }
        return array_keys($fp);
    }
}
