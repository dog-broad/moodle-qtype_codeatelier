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

// Unit test for the client-side unzip (run with `node --test`). Builds a tiny
// zip in memory (one stored entry, one deflated entry, a directory entry and a
// __MACOSX entry to be skipped) and checks unzip() reconstructs the files.

import {test} from 'node:test';
import assert from 'node:assert/strict';
import zlib from 'node:zlib';
import {unzip} from '../../amd/src/unzip.js';

const u32 = (n) => {
    const b = Buffer.alloc(4);
    b.writeUInt32LE(n >>> 0, 0);
    return b;
};
const u16 = (n) => {
    const b = Buffer.alloc(2);
    b.writeUInt16LE(n & 0xffff, 0);
    return b;
};

// Minimal zip writer: each entry {name, content, store}. Uses raw deflate
// (method 8) unless `store` is set (method 0), matching what unzip() reads.
const buildZip = (entries) => {
    const locals = [];
    const centrals = [];
    let offset = 0;
    for (const e of entries) {
        const name = Buffer.from(e.name, 'utf8');
        const raw = Buffer.from(e.content, 'utf8');
        const method = e.store ? 0 : 8;
        const data = e.store ? raw : zlib.deflateRawSync(raw);
        const crc = zlib.crc32(raw);
        const common = Buffer.concat([
            u16(20), u16(0), u16(method), u16(0), u16(0),
            u32(crc), u32(data.length), u32(raw.length),
            u16(name.length),
        ]);
        const local = Buffer.concat([
            u32(0x04034b50), common, u16(0), name, data,
        ]);
        const central = Buffer.concat([
            u32(0x02014b50), u16(20), common,
            u16(0), // extra field length
            u16(0), // file comment length
            u16(0), // disk number start
            u16(0), // internal file attributes
            u32(0), // external file attributes
            u32(offset), name,
        ]);
        locals.push(local);
        centrals.push(central);
        offset += local.length;
    }
    const cd = Buffer.concat(centrals);
    const localBlob = Buffer.concat(locals);
    const eocd = Buffer.concat([
        u32(0x06054b50), u16(0), u16(0),
        u16(entries.length), u16(entries.length),
        u32(cd.length), u32(localBlob.length), u16(0),
    ]);
    return Buffer.concat([localBlob, cd, eocd]);
};

test('unzip reconstructs stored and deflated entries, skips dirs/__MACOSX', async () => {
    const longText = 'repeat '.repeat(40).trim();
    const zip = buildZip([
        {name: 'src/', content: '', store: true},
        {name: 'src/Main.java', content: 'public class Main {}', store: true},
        {name: 'readme.txt', content: longText},
        {name: '__MACOSX/._readme.txt', content: 'junk'},
        {name: '.DS_Store', content: 'junk'},
    ]);
    const ab = zip.buffer.slice(zip.byteOffset, zip.byteOffset + zip.byteLength);
    const files = await unzip(ab);
    const byName = Object.fromEntries(files.map((f) => [f.name, f.content]));

    assert.deepEqual(Object.keys(byName).sort(), ['readme.txt', 'src/Main.java']);
    assert.equal(byName['src/Main.java'], 'public class Main {}');
    assert.equal(byName['readme.txt'], longText);
});

test('unzip decodes UTF-8 content correctly', async () => {
    const zip = buildZip([{name: 'greeting.txt', content: 'café — ünïcode ✓', store: true}]);
    const ab = zip.buffer.slice(zip.byteOffset, zip.byteOffset + zip.byteLength);
    const files = await unzip(ab);
    assert.equal(files.length, 1);
    assert.equal(files[0].content, 'café — ünïcode ✓');
});
