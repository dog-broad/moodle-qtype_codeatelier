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

/**
 * A minimal in-browser ZIP reader for the project-file import: parse the central
 * directory and inflate stored (method 0) and deflated (method 8) entries via the
 * native DecompressionStream — no bundled zip dependency. Text entries only.
 *
 * @module     qtype_codeatelier/unzip
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SIG_EOCD = 0x06054b50;
const SIG_CENTRAL = 0x02014b50;
const SIG_LOCAL = 0x04034b50;

/**
 * Inflate a raw-deflate byte array via the browser's DecompressionStream.
 *
 * @param {Uint8Array} bytes
 * @return {Promise<Uint8Array>}
 */
const inflateRaw = async (bytes) => {
    if (typeof DecompressionStream === 'undefined') {
        throw new Error('This browser cannot read zip files; paste the files instead.');
    }
    const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
    const buf = await new Response(stream).arrayBuffer();
    return new Uint8Array(buf);
};

/**
 * Read a ZIP archive into a list of text files. Directory entries and macOS junk
 * (`__MACOSX/`, `.DS_Store`) are skipped; unsupported compression methods are
 * dropped silently.
 *
 * @param {ArrayBuffer} arrayBuffer the zip file bytes
 * @return {Promise<Array<{name: String, content: String}>>}
 */
export const unzip = async (arrayBuffer) => {
    const dv = new DataView(arrayBuffer);
    const u8 = new Uint8Array(arrayBuffer);
    const dec = new TextDecoder('utf-8');

    // Find the End Of Central Directory record, scanning back from the end (its
    // trailing comment is almost always empty, so this is near the tail).
    let eocd = -1;
    const min = Math.max(0, u8.length - 22 - 65536);
    for (let i = u8.length - 22; i >= min; i--) {
        if (dv.getUint32(i, true) === SIG_EOCD) {
            eocd = i;
            break;
        }
    }
    if (eocd < 0) {
        throw new Error('not a zip file');
    }
    const count = dv.getUint16(eocd + 10, true);
    let off = dv.getUint32(eocd + 16, true);

    const entries = [];
    for (let n = 0; n < count && off + 46 <= u8.length; n++) {
        if (dv.getUint32(off, true) !== SIG_CENTRAL) {
            break;
        }
        const method = dv.getUint16(off + 10, true);
        const compSize = dv.getUint32(off + 20, true);
        const nameLen = dv.getUint16(off + 28, true);
        const extraLen = dv.getUint16(off + 30, true);
        const commentLen = dv.getUint16(off + 32, true);
        const localOff = dv.getUint32(off + 42, true);
        const name = dec.decode(u8.subarray(off + 46, off + 46 + nameLen));
        off += 46 + nameLen + extraLen + commentLen;

        if (/\/$/.test(name) || /(^|\/)__MACOSX\//.test(name) || /(^|\/)\.DS_Store$/.test(name)) {
            continue;
        }
        if (dv.getUint32(localOff, true) !== SIG_LOCAL) {
            continue;
        }
        const lNameLen = dv.getUint16(localOff + 26, true);
        const lExtraLen = dv.getUint16(localOff + 28, true);
        const dataOff = localOff + 30 + lNameLen + lExtraLen;
        entries.push({name: name, method: method, data: u8.subarray(dataOff, dataOff + compSize)});
    }

    const files = [];
    for (const e of entries) {
        let bytes = null;
        if (e.method === 0) {
            bytes = e.data;
        } else if (e.method === 8) {
            bytes = await inflateRaw(e.data);
        } else {
            continue;
        }
        files.push({name: e.name, content: dec.decode(bytes)});
    }
    return files;
};
