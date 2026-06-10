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

namespace qtype_codeatelier;

use qtype_codeatelier\local\integrity\cohort_analyser;
use qtype_codeatelier\local\integrity\fingerprint;

/**
 * Tests for the code-similarity engine: the structural fingerprint and the
 * cohort-relative detection (floor, uniform suppression, baseline outliers, clusters).
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\local\integrity\cohort_analyser
 * @covers     \qtype_codeatelier\local\integrity\fingerprint
 */
final class cohort_analyser_test extends \advanced_testcase {
    /**
     * Build a submission record the analyser accepts.
     *
     * @param int $key
     * @param string $language
     * @param string $code
     * @return \stdClass
     */
    private function sub(int $key, string $language, string $code): \stdClass {
        return (object) [
            'key' => $key,
            'label' => 'Student ' . $key,
            'language' => $language,
            'code' => $code,
            'qaid' => $key,
            'quizattemptid' => null,
        ];
    }

    /**
     * The fingerprint erases identifier names and reformatting: a verbatim copy, a
     * fully variable-renamed copy, and a reformatted copy are all near-identical, while
     * an independent solution is not.
     */
    public function test_fingerprint_catches_disguised_copies(): void {
        $orig = "def two_sum(nums, target):\n    seen = {}\n    for i, n in enumerate(nums):\n"
              . "        if target - n in seen:\n            return [seen[target - n], i]\n"
              . "        seen[n] = i\n    return []";
        $renamed = "def solve(arr, goal):\n    cache = {}\n    for idx, val in enumerate(arr):\n"
                 . "        if goal - val in cache:\n            return [cache[goal - val], idx]\n"
                 . "        cache[val] = idx\n    return []";
        $reformatted = "def two_sum(nums, target):\n\n    # lookup table\n    seen = {}\n\n"
                     . "    for i, n in enumerate(nums):   # one pass\n        if target - n in seen:\n"
                     . "            return [ seen[target - n] , i ]\n        seen[n] = i\n    return []";
        $independent = "def two_sum(nums, target):\n    for i in range(len(nums)):\n"
                     . "        for j in range(i + 1, len(nums)):\n"
                     . "            if nums[i] + nums[j] == target:\n                return [i, j]\n    return []";

        $sim = fn($x, $y) => $this->jaccard(fingerprint::of($x), fingerprint::of($y));
        $this->assertEqualsWithDelta(
            1.0,
            $sim($orig, $renamed),
            0.001,
            'a fully renamed copy must still be detected'
        );
        $this->assertEqualsWithDelta(
            1.0,
            $sim($orig, $reformatted),
            0.001,
            'reformatting must not hide a copy'
        );
        $this->assertLessThan(
            0.3,
            $sim($orig, $independent),
            'an independent solution must score low'
        );
    }

    /**
     * A trivial question (one obvious short answer) is below the complexity floor and
     * is reported low-signal — never flagged.
     */
    public function test_trivial_question_is_low_signal(): void {
        $subs = [
            $this->sub(1, 'python', "def add(a, b):\n    return a + b"),
            $this->sub(2, 'python', "def add(a, b):\n    return a+b"),
            $this->sub(3, 'python', "def add(x, y):\n    return x + y"),
            $this->sub(4, 'python', "def add(a, b):\n    s = a + b\n    return s"),
        ];
        $results = cohort_analyser::analyse($subs);
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->analyzable);
        $this->assertSame(cohort_analyser::REASON_TOOSHORT, $results[0]->reason);
        $this->assertSame([], $results[0]->clusters);
    }

    /**
     * A moderately-complex question with one dominant shape (every submission similar)
     * is suppressed by the uniform-cohort guard — the naive-threshold failure mode.
     */
    public function test_uniform_cohort_is_suppressed(): void {
        $tpl = function (string $var, string $loop): string {
            return "def fizzbuzz($var):\n    for i in range(1, $var + 1):\n"
                 . "        if $loop:\n            print('FizzBuzz')\n"
                 . "        elif i % 3 == 0:\n            print('Fizz')\n"
                 . "        elif i % 5 == 0:\n            print('Buzz')\n"
                 . "        else:\n            print(i)";
        };
        $subs = [
            $this->sub(1, 'python', $tpl('n', 'i % 15 == 0')),
            $this->sub(2, 'python', $tpl('limit', 'i % 15 == 0')),
            $this->sub(3, 'python', $tpl('n', 'i % 3 == 0 and i % 5 == 0')),
            $this->sub(4, 'python', $tpl('num', 'i % 15 == 0')),
            $this->sub(5, 'python', $tpl('count', 'i % 15 == 0')),
        ];
        $results = cohort_analyser::analyse($subs);
        $this->assertCount(1, $results);
        $this->assertFalse(
            $results[0]->analyzable,
            'a uniform cohort must not be analysed for outliers'
        );
        $this->assertSame(cohort_analyser::REASON_UNIFORM, $results[0]->reason);
        $this->assertGreaterThan(cohort_analyser::UNIFORM_MEDIAN, $results[0]->median);
        $this->assertSame([], $results[0]->clusters);
    }

    /**
     * A varied cohort with one disguised copy pair: the pair is flagged as a cluster,
     * the independent solutions are not.
     */
    public function test_varied_cohort_flags_only_the_copy(): void {
        $subs = [
            // Algorithmically-distinct independent solutions (brute force; sorted
            // two-pointer) — nothing structurally close to each other or to the copy.
            $this->sub(1, 'python', "def two_sum(nums, target):\n    for i in range(len(nums)):\n"
                    . "        for j in range(i + 1, len(nums)):\n"
                    . "            if nums[i] + nums[j] == target:\n                return [i, j]\n    return []"),
            $this->sub(2, 'python', "def two_sum(nums, target):\n    pairs = sorted(range(len(nums)), key=lambda k: nums[k])\n"
                    . "    lo, hi = 0, len(nums) - 1\n    while lo < hi:\n        s = nums[pairs[lo]] + nums[pairs[hi]]\n"
                    . "        if s == target:\n            return [pairs[lo], pairs[hi]]\n        elif s < target:\n"
                    . "            lo += 1\n        else:\n            hi -= 1\n    return []"),
            // The copy pair: 3 original, 4 = 3 with every identifier renamed + reformatted.
            $this->sub(3, 'python', "def two_sum(nums, target):\n    lookup = {}\n    for index in range(len(nums)):\n"
                    . "        need = target - nums[index]\n        if need in lookup:\n"
                    . "            return [lookup[need], index]\n        lookup[nums[index]] = index\n    return []"),
            $this->sub(4, 'python', "def two_sum(arr, goal):\n    table = {}\n    for pos in range(len(arr)):\n"
                    . "        want = goal - arr[pos]\n        if want in table:\n"
                    . "            return [table[want], pos]\n        table[arr[pos]] = pos\n    return []"),
        ];
        $results = cohort_analyser::analyse($subs);
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->analyzable);
        $this->assertCount(1, $results[0]->clusters, 'exactly one cluster — the copy pair');
        $cluster = $results[0]->clusters[0];
        $this->assertSame(2, $cluster->size);
        $labels = array_map(fn($m) => $m->label, $cluster->members);
        sort($labels);
        $this->assertSame(['Student 3', 'Student 4'], $labels);
        $this->assertGreaterThanOrEqual(0.9, $cluster->maxsim);
    }

    /**
     * Submissions are only compared within a language: the same code in two languages
     * is two separate one-submission groups (skipped), never a cross-language match.
     */
    public function test_groups_are_per_language(): void {
        $code = "def f(n):\n    total = 0\n    for i in range(n):\n        total += i * i\n    return total";
        $subs = [
            $this->sub(1, 'python', $code),
            $this->sub(2, 'javascript', $code),
        ];
        // One submission per language -> nothing to compare in either group.
        $this->assertSame([], cohort_analyser::analyse($subs));
    }

    /**
     * Three mutually-similar submissions form a single cluster (transitive grouping),
     * not three separate pairs.
     */
    public function test_transitive_pairs_form_one_cluster(): void {
        $base = "def parse(text):\n    result = []\n    for line in text.split(chr(10)):\n"
              . "        parts = line.strip().split(chr(44))\n        if len(parts) >= 2:\n"
              . "            result.append((parts[0], int(parts[1])))\n    return result";
        // Three near-identical copies (renamed) + two independent solutions.
        $copy = fn($a, $b, $c) => str_replace(['result', 'parts', 'line'], [$a, $b, $c], $base);
        $subs = [
            $this->sub(1, 'python', $base),
            $this->sub(2, 'python', $copy('out', 'cols', 'row')),
            $this->sub(3, 'python', $copy('acc', 'bits', 'ln')),
            $this->sub(4, 'python', "def parse(text):\n    return [(p.split(chr(44))[0], int(p.split(chr(44))[1]))\n"
                    . "            for p in text.split(chr(10)) if chr(44) in p]"),
            $this->sub(5, 'python', "def parse(text):\n    pairs = {}\n    for entry in text.splitlines():\n"
                    . "        k, _, v = entry.partition(chr(44))\n        pairs[k.strip()] = int(v)\n    return list(pairs.items())"),
        ];
        $results = cohort_analyser::analyse($subs);
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->analyzable);
        $this->assertCount(1, $results[0]->clusters);
        $this->assertSame(
            3,
            $results[0]->clusters[0]->size,
            'the three renamed copies form one cluster, not separate pairs'
        );
    }

    /**
     * Jaccard helper mirroring the analyser's private one, for the fingerprint test.
     *
     * @param int[] $a
     * @param int[] $b
     * @return float
     */
    private function jaccard(array $a, array $b): float {
        if (!$a && !$b) {
            return 1.0;
        }
        $sa = array_flip($a);
        $sb = array_flip($b);
        $union = count($sa + $sb);
        return $union ? count(array_intersect_key($sa, $sb)) / $union : 0.0;
    }
}
