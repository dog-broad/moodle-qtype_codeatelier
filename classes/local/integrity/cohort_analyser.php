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
 * Find suspiciously-similar code within a question's cohort.
 *
 * Similarity is not plagiarism: on a constrained problem high similarity is the
 * expected baseline, not evidence. So detection is cohort-relative, never an absolute
 * threshold, through three guards:
 *
 *  1. A complexity floor — a cohort whose typical submission is too short to carry a
 *     signal is reported as low-signal and nothing is flagged.
 *  2. Uniform-cohort suppression — if the whole cohort is similar (one dominant
 *     solution shape), copies cannot be told from coincidence, so nothing is flagged.
 *  3. Outliers above the cohort's own (low) baseline — only when the cohort is
 *     genuinely varied are pairs flagged, and only those far above the baseline.
 *
 * Flagged pairs are grouped into clusters (connected components). Submissions are
 * compared only within a single language. The output is a distribution and a set of
 * clusters for a human to read, never an automated accusation. The constants below are
 * tunable starting points, not load-bearing thresholds.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_analyser {
    /** @var int below this median normalized-token length, a cohort is not analyzable. */
    const MIN_TOKENS = 25;

    /** @var int below this median fingerprint count, a cohort is not analyzable. */
    const MIN_FINGERPRINTS = 8;

    /** @var float median pairwise similarity above this means one dominant shape — suppress. */
    const UNIFORM_MEDIAN = 0.40;

    /** @var float a flagged pair must exceed median + K*MAD of the cohort distribution. */
    const OUTLIER_K = 6.0;

    /** @var float and must also clear this absolute similarity. */
    const HARD_FLOOR = 0.35;

    /** @var string reason code: the cohort's submissions are too short to analyze. */
    const REASON_TOOSHORT = 'tooshort';

    /** @var string reason code: the whole cohort is similar (one dominant solution shape). */
    const REASON_UNIFORM = 'uniform';

    /**
     * Analyse a question's submissions, grouped by language.
     *
     * @param array $submissions list of objects, each with at least: key (a stable
     *     per-submission id), label (display name), language, code, and optionally
     *     qaid + quizattemptid (carried through for review links)
     * @return \stdClass[] one result per language group with >= 2 submissions, each:
     *     {language, count, analyzable, reason, median, max, clusters}
     */
    public static function analyse(array $submissions): array {
        $bylang = [];
        foreach ($submissions as $s) {
            $bylang[$s->language][] = $s;
        }
        ksort($bylang);
        $results = [];
        foreach ($bylang as $language => $subs) {
            if (count($subs) < 2) {
                continue;
            }
            $results[] = self::analyse_language((string) $language, $subs);
        }
        return $results;
    }

    /**
     * Analyse one same-language group.
     *
     * @param string $language
     * @param array $subs
     * @return \stdClass
     */
    private static function analyse_language(string $language, array $subs): \stdClass {
        $result = (object) [
            'language' => $language,
            'count' => count($subs),
            'analyzable' => false,
            'reason' => null,
            'median' => null,
            'max' => null,
            'clusters' => [],
        ];

        // Fingerprint each submission once.
        $fps = [];
        $lengths = [];
        foreach ($subs as $i => $s) {
            $fps[$i] = fingerprint::of($s->code);
            $lengths[] = fingerprint::token_count($s->code);
        }

        // Guard 1: complexity floor.
        $medlen = self::median($lengths);
        $medfp = self::median(array_map('count', $fps));
        if ($medlen < self::MIN_TOKENS || $medfp < self::MIN_FINGERPRINTS) {
            $result->reason = self::REASON_TOOSHORT;
            return $result;
        }

        // Pairwise Jaccard similarity.
        $pairs = [];
        $keys = array_keys($fps);
        $count = count($keys);
        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                $pairs[] = [$keys[$a], $keys[$b], self::jaccard($fps[$keys[$a]], $fps[$keys[$b]])];
            }
        }
        $sims = array_map(fn($p) => $p[2], $pairs);
        $median = self::median($sims);
        $result->median = $median;
        $result->max = $sims ? max($sims) : 0.0;

        // Guard 2: uniform-cohort suppression.
        if ($median > self::UNIFORM_MEDIAN) {
            $result->reason = self::REASON_UNIFORM;
            return $result;
        }

        // Flag outliers above the cohort baseline, then group into clusters.
        $result->analyzable = true;
        $cutoff = max(self::HARD_FLOOR, $median + self::OUTLIER_K * self::mad($sims, $median));
        $flagged = array_values(array_filter($pairs, fn($p) => $p[2] >= $cutoff));
        $result->clusters = self::cluster($flagged, $subs);
        return $result;
    }

    /**
     * Group flagged pairs into clusters (connected components) via union-find.
     *
     * @param array $flagged list of [indexA, indexB, similarity]
     * @param array $subs the submissions, indexed as the pair indices reference them
     * @return \stdClass[] each {members: [{label,qaid,quizattemptid}], pairs, maxsim, size}
     */
    private static function cluster(array $flagged, array $subs): array {
        if (!$flagged) {
            return [];
        }
        // Union-find over the submission indices that appear in a flagged pair.
        $parent = [];
        $find = function ($x) use (&$parent, &$find) {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        foreach ($flagged as [$a, $b, $sim]) {
            $parent[$a] = $parent[$a] ?? $a;
            $parent[$b] = $parent[$b] ?? $b;
            $parent[$find($a)] = $find($b);
        }

        // Bucket pairs and members by their cluster root.
        $buckets = [];
        foreach ($flagged as [$a, $b, $sim]) {
            $root = $find($a);
            $buckets[$root]['pairs'][] = (object) [
                'a' => $subs[$a]->label,
                'b' => $subs[$b]->label,
                'sim' => $sim,
            ];
            $buckets[$root]['members'][$a] = $subs[$a];
            $buckets[$root]['members'][$b] = $subs[$b];
        }

        $clusters = [];
        foreach ($buckets as $bucket) {
            $maxsim = 0.0;
            foreach ($bucket['pairs'] as $p) {
                $maxsim = max($maxsim, $p->sim);
            }
            $members = [];
            foreach ($bucket['members'] as $s) {
                $members[] = (object) [
                    'label' => $s->label,
                    'qaid' => $s->qaid ?? null,
                    'quizattemptid' => $s->quizattemptid ?? null,
                ];
            }
            $clusters[] = (object) [
                'members' => $members,
                'pairs' => $bucket['pairs'],
                'maxsim' => $maxsim,
                'size' => count($members),
            ];
        }
        // Most-similar clusters first.
        usort($clusters, fn($x, $y) => $y->maxsim <=> $x->maxsim);
        return $clusters;
    }

    /**
     * Jaccard overlap of two fingerprint sets.
     *
     * @param int[] $a
     * @param int[] $b
     * @return float 0..1
     */
    private static function jaccard(array $a, array $b): float {
        if (!$a && !$b) {
            return 1.0;
        }
        $sa = array_flip($a);
        $sb = array_flip($b);
        $union = count($sa + $sb);
        return $union ? count(array_intersect_key($sa, $sb)) / $union : 0.0;
    }

    /**
     * Median of a numeric list.
     *
     * @param array $xs
     * @return float
     */
    private static function median(array $xs): float {
        if (!$xs) {
            return 0.0;
        }
        sort($xs);
        $n = count($xs);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $xs[$mid] : ($xs[$mid - 1] + $xs[$mid]) / 2;
    }

    /**
     * Median absolute deviation about a given median.
     *
     * @param array $xs
     * @param float $median
     * @return float
     */
    private static function mad(array $xs, float $median): float {
        if (!$xs) {
            return 0.0;
        }
        return self::median(array_map(fn($x) => abs($x - $median), $xs));
    }
}
