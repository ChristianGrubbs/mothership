import { crc32 } from 'node:zlib';

// Stored ZIP entries keep the synthetic 3MF independent of the DAM build's npm install.
// Playwright installs only tests/e2e-pw dependencies, not the sibling DAM package.
export const encode = files => {
    const entries = [], directory = [];
    let offset = 0;
    for (const [name, contents] of Object.entries(files)) {
        const filename = Buffer.from(name), data = Buffer.from(contents);
        const header = Buffer.alloc(30), central = Buffer.alloc(46);
        header.writeUInt32LE(0x04034b50, 0);
        header.writeUInt16LE(20, 4);
        header.writeUInt32LE(crc32(data), 14);
        header.writeUInt32LE(data.length, 18);
        header.writeUInt32LE(data.length, 22);
        header.writeUInt16LE(filename.length, 26);
        central.writeUInt32LE(0x02014b50, 0);
        central.writeUInt16LE(20, 4);
        central.writeUInt16LE(20, 6);
        header.copy(central, 8, 6, 28);
        central.writeUInt32LE(offset, 42);
        entries.push(header, filename, data);
        directory.push(central, filename);
        offset += header.length + filename.length + data.length;
    }
    const table = Buffer.concat(directory), end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(directory.length / 2, 8);
    end.writeUInt16LE(directory.length / 2, 10);
    end.writeUInt32LE(table.length, 12);
    end.writeUInt32LE(offset, 16);
    return Uint8Array.from(Buffer.concat([...entries, table, end])).buffer;
};
const xml = '<model unit="millimeter"><resources><object id="1"><mesh><vertices><vertex x="0" y="0" z="0"/><vertex x="2" y="0" z="0"/><vertex x="0" y="2" z="0"/></vertices><triangles><triangle v1="0" v2="1" v3="2" paint_color="481"/></triangles></mesh></object></resources></model>';
export const fixture = () => encode({
    '_rels/.rels':'<Relationships><Relationship Target="/3D/3dmodel.model" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel"/></Relationships>',
    '3D/3dmodel.model':'<model unit="millimeter" xmlns:p="urn:production"><resources><object id="2"><components><component objectid="1" p:path="/3D/Objects/a.model" transform="1 0 0 0 1 0 0 0 1 3 0 0"/></components></object></resources><build><item objectid="2" transform="1 0 0 0 1 0 0 0 1 10 20 0"/><item objectid="2" transform="1 0 0 0 1 0 0 0 1 300 20 0"/></build></model>',
    '3D/Objects/a.model':xml,
    'Metadata/project_settings.config':JSON.stringify({filament_colour:['#FF0000','#00FF00'],printable_area:['0x0','256x0','256x256','0x256']}),
    'Metadata/model_settings.config':'<config><object id="2"><metadata key="name" value="Two-color part"/><metadata key="extruder" value="2"/><part id="1" subtype="normal_part"/></object><plate><metadata key="plater_id" value="1"/><model_instance><metadata key="object_id" value="2"/><metadata key="instance_id" value="0"/></model_instance></plate><plate><metadata key="plater_id" value="2"/><model_instance><metadata key="object_id" value="2"/><metadata key="instance_id" value="1"/></model_instance></plate></config>',
});

/**
 * A binary STL (80-byte header, uint32 count, 50 bytes per facet). Facet
 * normals are written as zero by default -- the most common real-world STL
 * defect, and the case a renderer has to survive by computing its own.
 */
export function stlFixture({ triangles = [[[0, 0, 0], [2, 0, 0], [0, 2, 0]]], normals = null, color = null } = {}) {
    const bytes = new Uint8Array(84 + 50 * triangles.length);
    const view = new DataView(bytes.buffer);
    // `color` = [r, g, b] in 0..31: the non-standard binary STL colour extension STLLoader
    // understands -- a "COLOR=rgba" default in the header and, per facet, bit 15 CLEAR in the
    // attribute word with 5-bit RGB packed into the low bits.
    new TextEncoder().encodeInto(color ? 'COLOR=' : 'mothership synthetic stl', bytes);
    if (color) bytes.set([color[0] * 8, color[1] * 8, color[2] * 8, 255], 6);
    view.setUint32(80, triangles.length, true);
    triangles.forEach((triangle, index) => {
        let offset = 84 + index * 50;
        for (const value of normals?.[index] ?? [0, 0, 0]) { view.setFloat32(offset, value, true); offset += 4; }
        for (const vertex of triangle) for (const value of vertex) { view.setFloat32(offset, value, true); offset += 4; }
        view.setUint16(offset, color ? (color[0] & 0x1f) | ((color[1] & 0x1f) << 5) | ((color[2] & 0x1f) << 10) : 0, true);
    });
    return bytes.buffer;
}

export const asciiStlFixture = () => new TextEncoder().encode(
    'solid tri\n facet normal 0 0 0\n  outer loop\n   vertex 0 0 0\n   vertex 2 0 0\n   vertex 0 2 0\n  endloop\n endfacet\nendsolid tri\n',
).buffer;
