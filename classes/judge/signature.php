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

namespace qtype_codeatelier\judge;

/**
 * A bounded, typed function signature for static-language judge grading.
 *
 * Static languages cannot be called with arbitrary JSON arguments the way the
 * dynamic ones can, so a function question declares the function's contract — its
 * parameter types and return type — over a small fixed type grammar. From the
 * signature this class drives both ends of a simple line protocol: it encodes the
 * test's JSON arguments into typed stdin lines the generated harness reads, and
 * decodes the harness's printed return into a native PHP value the shared grader
 * compares. All (de)serialization lives here, so each language's harness only has
 * to read typed values, call the function, and print the result.
 *
 * Grammar: a comma-separated parameter list, then `->`, then the return type.
 *   scalar  := int | long | double | bool | string
 *   type    := scalar | list<scalar>
 *   example := "int, int -> int"   "string -> bool"   "list<int> -> list<int>"   "-> double"
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signature {
    /** @var string[] the scalar type names. */
    const SCALARS = ['int', 'long', 'double', 'bool', 'string'];

    /** @var string[] the parameter types in order. */
    private $params;

    /** @var string the return type. */
    private $return;

    /**
     * Build a parsed signature from its parameter and return types.
     *
     * @param string[] $params the parameter types
     * @param string $return the return type
     */
    private function __construct(array $params, string $return) {
        $this->params = $params;
        $this->return = $return;
    }

    /**
     * Parse a signature string, or throw on a malformed one.
     *
     * @param string $text e.g. "int, int -> int"
     * @return self
     * @throws \invalid_parameter_exception on a malformed signature
     */
    public static function parse(string $text): self {
        if (strpos($text, '->') === false) {
            throw new \invalid_parameter_exception("signature needs '->': {$text}");
        }
        [$parampart, $returnpart] = explode('->', $text, 2);
        $params = [];
        // Split on top-level commas only, so a comma inside map<string,int> does not
        // break the type apart.
        foreach (self::split_params($parampart) as $p) {
            $p = trim($p);
            if ($p !== '') {
                $params[] = self::valid_type($p);
            }
        }
        $return = self::valid_type(trim($returnpart));
        return new self($params, $return);
    }

    /**
     * Split a parameter list on commas that are not inside angle brackets.
     *
     * @param string $list the parameter part of the signature
     * @return string[] the raw type tokens
     */
    private static function split_params(string $list): array {
        $parts = [];
        $depth = 0;
        $current = '';
        $length = strlen($list);
        for ($i = 0; $i < $length; $i++) {
            $ch = $list[$i];
            if ($ch === '<') {
                $depth++;
            } else if ($ch === '>') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    /**
     * Whether a signature string is well-formed (for form validation).
     *
     * @param string $text the signature string
     * @return bool
     */
    public static function is_valid(string $text): bool {
        try {
            self::parse($text);
            return true;
        } catch (\invalid_parameter_exception $e) {
            return false;
        }
    }

    /**
     * Validate one type token, returning it normalised.
     *
     * @param string $type the type token
     * @return string
     * @throws \invalid_parameter_exception
     */
    private static function valid_type(string $type): string {
        if (in_array($type, self::SCALARS, true)) {
            return $type;
        }
        if (preg_match('/^list<(\w+)>$/', $type, $m) && in_array($m[1], self::SCALARS, true)) {
            return $type;
        }
        if (preg_match('/^map<\s*string\s*,\s*(\w+)\s*>$/', $type, $m) && in_array($m[1], self::SCALARS, true)) {
            // Keys are always strings; the value is a scalar.
            return 'map<string,' . $m[1] . '>';
        }
        throw new \invalid_parameter_exception("unsupported type: {$type}");
    }

    /**
     * The parameter types, in order.
     *
     * @return string[]
     */
    public function params(): array {
        return $this->params;
    }

    /**
     * The return type.
     *
     * @return string
     */
    public function return_type(): string {
        return $this->return;
    }

    /**
     * Whether a type is a list, and of which scalar.
     *
     * @param string $type a validated type
     * @return string|null the element scalar, or null if the type is scalar
     */
    public static function list_element(string $type): ?string {
        return preg_match('/^list<(\w+)>$/', $type, $m) ? $m[1] : null;
    }

    /**
     * Whether a type is a string-keyed map, and of which value scalar.
     *
     * @param string $type a validated type
     * @return string|null the value scalar, or null if the type is not a map
     */
    public static function map_value(string $type): ?string {
        return preg_match('/^map<string,(\w+)>$/', $type, $m) ? $m[1] : null;
    }

    /**
     * Whether a type is a bare scalar (not a list or map).
     *
     * @param string $type a validated type
     * @return bool
     */
    public static function is_scalar(string $type): bool {
        return in_array($type, self::SCALARS, true);
    }

    /**
     * Encode a test's decoded JSON arguments into the typed stdin protocol the
     * generated harness reads: each argument in order, a scalar on its own line,
     * a list as a count line then one element per line.
     *
     * @param array $args the arguments (already json_decoded into PHP values)
     * @return string the stdin payload
     */
    public function encode_args(array $args): string {
        $lines = [];
        foreach ($this->params as $i => $type) {
            $value = $args[$i] ?? null;
            $mapvalue = self::map_value($type);
            $element = self::list_element($type);
            if ($mapvalue !== null) {
                // A map: a count line, then a key line and a value line per entry.
                $entries = is_object($value) ? (array) $value : (is_array($value) ? $value : []);
                $lines[] = (string) count($entries);
                foreach ($entries as $k => $v) {
                    $lines[] = (string) $k;
                    $lines[] = self::encode_scalar($v, $mapvalue);
                }
            } else if ($element === null) {
                $lines[] = self::encode_scalar($value, $type);
            } else {
                $items = is_array($value) ? array_values($value) : [];
                $lines[] = (string) count($items);
                foreach ($items as $item) {
                    $lines[] = self::encode_scalar($item, $element);
                }
            }
        }
        return $lines ? implode("\n", $lines) . "\n" : '';
    }

    /**
     * Decode the harness's printed output into the native return value the shared
     * structured matcher compares (so int 5 / float 5.0 and arrays deep-equal).
     *
     * @param string $stdout the harness stdout
     * @return mixed the native return value
     */
    public function decode_return(string $stdout) {
        $mapvalue = self::map_value($this->return);
        $element = self::list_element($this->return);
        if ($mapvalue === null && $element === null) {
            return self::decode_scalar($stdout, $this->return, true);
        }
        $stdout = str_replace("\r\n", "\n", $stdout);
        $lines = explode("\n", rtrim($stdout, "\n"));
        $count = (int) array_shift($lines);
        if ($mapvalue !== null) {
            // A map: a key line then a value line per entry. Return an object so it
            // deep-equals the json_decode of the expected (a JSON object -> stdClass).
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $key = $lines[$i * 2] ?? '';
                $out[$key] = self::decode_scalar($lines[$i * 2 + 1] ?? '', $mapvalue, false);
            }
            return (object) $out;
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = self::decode_scalar($lines[$i] ?? '', $element, false);
        }
        return $out;
    }

    /**
     * Encode one scalar to its protocol token.
     *
     * @param mixed $value
     * @param string $type a scalar type
     * @return string
     */
    private static function encode_scalar($value, string $type): string {
        switch ($type) {
            case 'int':
            case 'long':
                return (string) (int) $value;
            case 'double':
                return rtrim(rtrim(sprintf('%.12g', (float) $value), '0'), '.');
            case 'bool':
                return $value ? '1' : '0';
            default: // String.
                return (string) $value;
        }
    }

    /**
     * Decode one scalar token to its native value.
     *
     * @param string $token the printed token
     * @param string $type a scalar type
     * @param bool $wholeoutput whether the token is the entire stdout (a scalar return)
     * @return int|float|bool|string
     */
    private static function decode_scalar(string $token, string $type, bool $wholeoutput) {
        switch ($type) {
            case 'int':
            case 'long':
                return (int) trim($token);
            case 'double':
                return (float) trim($token);
            case 'bool':
                return trim($token) === '1' || strtolower(trim($token)) === 'true';
            default: // String — a scalar return is the whole stdout minus one trailing newline.
                return $wholeoutput ? preg_replace('/\n$/', '', str_replace("\r\n", "\n", $token)) : $token;
        }
    }
}
