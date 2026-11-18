import { Group, Matrix4, Box3, Vector3, BufferGeometry, BufferAttribute, Color, Mesh, MeshStandardMaterial, DoubleSide, PlaneGeometry, LineSegments, LineBasicMaterial } from 'three';
import { STLLoader } from 'three/addons/loaders/STLLoader.js';

/** Mirrors the 3MF parser's decoded-geometry ceiling (docs/ops/3d-preview-acceptance.md). */
export const MAX_STL_TRIANGLES=3000000;

export function transform3MF(values) {
    return new Matrix4().set(values[0],values[3],values[6],values[9],values[1],values[4],values[7],values[10],values[2],values[5],values[8],values[11],0,0,0,1);
}

export function disposeModel(root) {
    const disposed=new Set();
    const once=value=>{if(value && !disposed.has(value)){disposed.add(value);value.dispose?.();}};
    root?.traverse(object=>{
        once(object.geometry);
        once(object.skeleton);
        for (const material of [object.material].flat().filter(Boolean)) {
            for (const value of Object.values(material)) if (value?.isTexture) {
                if (!disposed.has(value.source?.data)) { value.source?.data?.close?.(); disposed.add(value.source?.data); }
                once(value);
            }
            once(material);
        }
    });
}

export function buildThreeMFScene({ data }) {
    const root=new Group(), entries=[], cache=new Map();
    root.rotation.x=-Math.PI/2;
    const material=new MeshStandardMaterial({vertexColors:true,roughness:.65,metalness:0,side:DoubleSide});
    const colorCache=new Map();
    const color=value=>{
        const key=/^#[0-9a-f]{6}([0-9a-f]{2})?$/i.test(value || '') ? value.slice(0,7) : '#b8bec9';
        if (!colorCache.has(key)) colorCache.set(key,new Color(key));
        return colorCache.get(key);
    };
    function build({path,id,setting,inherited,ancestors}) {
        const key=`${path}#${id}`, source=data.objects[key];
        if (!source) throw new Error(`Missing 3MF object ${id}`);
        if (ancestors.includes(key) || ancestors.length>64) throw new Error('Cyclic or excessively nested 3MF components');
        const part=setting?.parts?.[id];
        if (part?.subtype && part.subtype!=='normal_part') return new Group();
        const filament=part?.extruder || inherited || 1;
        const group=new Group(); group.name=part?.name || source.name;
        if (source.positions) {
            const cacheKey=`${key}:${filament}`;
            let geometry=cache.get(cacheKey);
            if (!geometry) {
                geometry=new BufferGeometry();
                geometry.setAttribute('position',new BufferAttribute(source.positions,3));
                geometry.setAttribute('normal',new BufferAttribute(source.normals,3));
                const colors=new Float32Array(source.positions.length);
                for (let face=0;face<source.states.length;face++) for (let v=0;v<3;v++) {
                    const state=source.states[face];
                    const rgb=color(state ? data.palette[state-1] : source.colors[face*3+v] || data.palette[filament-1]);
                    colors.set([rgb.r,rgb.g,rgb.b],face*9+v*3);
                }
                geometry.setAttribute('color',new BufferAttribute(colors,3));
                geometry.computeBoundingBox(); geometry.computeBoundingSphere();
                cache.set(cacheKey,geometry);
            }
            group.add(new Mesh(geometry,material));
        }
        for (const child of source.components) {
            const object=build({path:child.path,id:child.id,setting,inherited:filament,ancestors:[...ancestors,key]});
            object.applyMatrix4(transform3MF(child.transform)); group.add(object);
        }
        return group;
    }
    try {
        for (const item of data.items) {
            const setting=data.settings[item.id];
            const object=build({path:item.path,id:item.id,setting,inherited:setting?.extruder,ancestors:[]});
            object.applyMatrix4(transform3MF(item.transform));
            const units=new Group(); units.scale.setScalar(item.unit); units.add(object); root.add(units);
            entries.push({key:item.key,name:setting?.name || object.name,object:units,enabled:item.printable});
        }
    } catch(error) { disposeModel(root); for(const geometry of cache.values()) geometry.dispose(); material.dispose(); throw error; }
    return {root,entries};
}

/**
 * STL carries triangles and nothing else: no colour, no UVs, no units, and
 * normals that are frequently zero. The mesh gets one neutral PBR material,
 * normals recomputed from its faces, and the same Z-up to Y-up remap the 3MF
 * path applies, because both formats come out of printer space.
 */
export function buildSTLScene({ buffer, name=null, maxTriangles=MAX_STL_TRIANGLES }) {
    const bytes=new Uint8Array(buffer);
    // Nothing under 84 bytes holds a facet in either encoding, and STLLoader's binary sniff reads offset 80 unguarded.
    if (bytes.byteLength<84) throw new Error('STL contains no triangles');
    // A binary STL declares its facet count up front; refuse before decoding 50 bytes a triangle.
    {
        const declared=new DataView(buffer).getUint32(80,true);
        if (84+declared*50===bytes.byteLength && declared>maxTriangles) throw new Error(`STL exceeds the ${maxTriangles.toLocaleString()} triangle preview limit`);
    }
    const geometry=new STLLoader().parse(buffer);
    const triangles=Math.floor(geometry.attributes.position.count/3);
    // STLLoader's ASCII path is lenient: junk or a truncated facet parses to an empty geometry,
    // which would otherwise reach the stage as a "ready" preview of nothing.
    if (triangles===0 || geometry.attributes.position.count%3!==0) { geometry.dispose(); throw new Error('STL contains no triangles'); }
    if (triangles>maxTriangles) { geometry.dispose(); throw new Error(`STL exceeds the ${maxTriangles.toLocaleString()} triangle preview limit`); }
    geometry.computeVertexNormals();
    geometry.computeBoundingBox(); geometry.computeBoundingSphere();
    // vertexColors stays off even when STLLoader decoded the non-standard colour extension: one neutral material for every STL.
    const material=new MeshStandardMaterial({color:'#b8bec9',roughness:.55,metalness:.05,side:DoubleSide,vertexColors:false});
    const root=new Group(); root.rotation.x=-Math.PI/2;
    const mesh=new Mesh(geometry,material); mesh.name=name || 'Model'; root.add(mesh);
    return {root,entries:[{key:'0',name:mesh.name,object:mesh,enabled:true}],triangles};
}

/** Grid pitch of a simulated build plate, in model millimetres. */
export const BUILD_PLATE_GRID_MM=10;
const BUILD_PLATE_MARGIN_MM=10;
const BUILD_PLATE_MIN_MM=100;

/**
 * A slicer's `printable_area` is four "XxY" corner strings; anything else means
 * the bed size is unknown and the plate is sized from the objects on it.
 */
export function printableAreaSize(printableArea) {
    const points=(Array.isArray(printableArea) ? printableArea : []).map(value=>String(value).split('x').map(Number)).filter(point=>point.length===2 && point.every(Number.isFinite));
    if (points.length<3) return null;
    const xs=points.map(([x])=>x), ys=points.map(([,y])=>y);
    const width=Math.max(...xs)-Math.min(...xs), depth=Math.max(...ys)-Math.min(...ys);
    return width>0 && depth>0 ? {width,depth} : null;
}

/**
 * One simulated build plate per slicer plate (or one for the whole build when
 * the file names none): a flat bed under the objects that belong to it, with a
 * 10 mm grid, so a 3MF reads as a print job rather than parts floating in
 * space. Plates are children of `root`, in the same printer-space (Z up)
 * coordinates as the geometry, and carry `userData.damBuildPlate` so framing
 * and the plate selector can tell them from the model.
 */
export function buildBuildPlates({ root, entries, plates=[], printableArea=[] }) {
    root.updateWorldMatrix(true,true);
    const toRoot=root.matrixWorld.clone().invert();
    const bed=printableAreaSize(printableArea);
    const groups=plates.length ? plates.map(plate=>({id:plate.id,name:plate.name || `Plate ${plate.id}`,entries:entries.filter(entry=>plate.instances.includes(entry.key))})) : [{id:null,name:'Build plate',entries}];
    const built=[];
    for (const group of groups) {
        const box=new Box3();
        for (const entry of group.entries) entry.object.traverse(object=>{
            if (!object.isMesh) return;
            if (!object.geometry.boundingBox) object.geometry.computeBoundingBox();
            box.union(object.geometry.boundingBox.clone().applyMatrix4(toRoot.clone().multiply(object.matrixWorld)));
        });
        if (box.isEmpty()) continue;
        const size=box.getSize(new Vector3()), center=box.getCenter(new Vector3());
        const width=Math.max(bed?.width || 0, size.x+BUILD_PLATE_MARGIN_MM*2, BUILD_PLATE_MIN_MM);
        const depth=Math.max(bed?.depth || 0, size.y+BUILD_PLATE_MARGIN_MM*2, BUILD_PLATE_MIN_MM);
        const plate=new Group(); plate.name=group.name; plate.userData.damBuildPlate=group.id ?? 'all';
        // Just under the lowest vertex so the bed never z-fights with a flat base.
        plate.position.set(center.x,center.y,box.min.z-.05);
        const bedMesh=new Mesh(new PlaneGeometry(width,depth),new MeshStandardMaterial({color:'#2b323f',roughness:.92,metalness:0,side:DoubleSide,transparent:true,opacity:.9}));
        bedMesh.userData.damBuildPlate=plate.userData.damBuildPlate; bedMesh.name=`${group.name} surface`;
        plate.add(bedMesh);
        const lines=[];
        const halfW=width/2, halfD=depth/2;
        for (let x=-halfW; x<=halfW+1e-6; x+=BUILD_PLATE_GRID_MM) lines.push(x,-halfD,0, x,halfD,0);
        for (let y=-halfD; y<=halfD+1e-6; y+=BUILD_PLATE_GRID_MM) lines.push(-halfW,y,0, halfW,y,0);
        const grid=new BufferGeometry(); grid.setAttribute('position',new BufferAttribute(new Float32Array(lines),3));
        const gridLines=new LineSegments(grid,new LineBasicMaterial({color:'#4b5567',transparent:true,opacity:.7}));
        gridLines.position.z=.02; gridLines.userData.damBuildPlate=plate.userData.damBuildPlate; plate.add(gridLines);
        const edge=new BufferGeometry(); edge.setAttribute('position',new BufferAttribute(new Float32Array([-halfW,-halfD,0, halfW,-halfD,0, halfW,-halfD,0, halfW,halfD,0, halfW,halfD,0, -halfW,halfD,0, -halfW,halfD,0, -halfW,-halfD,0]),3));
        const border=new LineSegments(edge,new LineBasicMaterial({color:'#8b96ab'})); border.position.z=.03; border.userData.damBuildPlate=plate.userData.damBuildPlate; plate.add(border);
        root.add(plate);
        built.push({id:group.id,object:plate,width,depth});
    }
    return built;
}
