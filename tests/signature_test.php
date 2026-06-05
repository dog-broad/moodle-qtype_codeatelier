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

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the typed function signature: parsing, validation, and the typed
 * stdin/stdout protocol used to drive static-language harnesses.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\judge\signature
 */
final class signature_test extends \advanced_testcase {

    public function test_parse_scalars_and_lists(): void {
        $s = signature::parse('int, int -> int');
        $this->assertSame(['int', 'int'], $s->params());
        $this->assertSame('int', $s->return_type());

        $s2 = signature::parse('list<int> -> list<string>');
        $this->assertSame(['list<int>'], $s2->params());
        $this->assertSame('list<string>', $s2->return_type());

        $s3 = signature::parse('-> double');
        $this->assertSame([], $s3->params());
        $this->assertSame('double', $s3->return_type());
    }

    public function test_validity(): void {
        $this->assertTrue(signature::is_valid('int,int->int'));
        $this->assertTrue(signature::is_valid('string -> bool'));
        $this->assertTrue(signature::is_valid('list<double> -> list<double>'));
        // No arrow, unknown scalar, unknown list element, nested list.
        $this->assertFalse(signature::is_valid('int'));
        $this->assertFalse(signature::is_valid('foo -> int'));
        $this->assertFalse(signature::is_valid('int -> bar'));
        $this->assertFalse(signature::is_valid('list<foo> -> int'));
        $this->assertFalse(signature::is_valid('list<list<int>> -> int'));
    }

    public function test_encode_args(): void {
        $this->assertSame("2\n3\n", signature::parse('int,int->int')->encode_args([2, 3]));
        $this->assertSame("hi\n", signature::parse('string->bool')->encode_args(['hi']));
        $this->assertSame("1\n", signature::parse('bool->bool')->encode_args([true]));
        // A single list argument: count then elements.
        $this->assertSame("3\n3\n1\n2\n", signature::parse('list<int>->int')->encode_args([[3, 1, 2]]));
    }

    public function test_decode_return(): void {
        $this->assertSame(5, signature::parse('->int')->decode_return('5'));
        $this->assertSame(true, signature::parse('->bool')->decode_return('1'));
        $this->assertSame(false, signature::parse('->bool')->decode_return('0'));
        $this->assertSame('hi', signature::parse('->string')->decode_return("hi\n"));
        $this->assertSame([3, 1, 2], signature::parse('->list<int>')->decode_return("3\n3\n1\n2"));
        $this->assertSame([], signature::parse('->list<int>')->decode_return('0'));
    }
}
