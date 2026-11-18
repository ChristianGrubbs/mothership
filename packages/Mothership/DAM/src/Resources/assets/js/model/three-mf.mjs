import { SaxesParser } from 'saxes';
import { unzipSync, strFromU8 } from 'three/addons/libs/fflate.module.js';
import { decodePaint } from './paint.mjs';
import { MAX_MODEL_BYTES, TOO_LARGE_MESSAGE } from './limits.mjs';

const identity = [1,0,0,0,1,0,0,0,1,0,0,0];
const local = name => name.split(':').pop();
const cleanPath = (path, base = '') => {
    const parts = (path.startsWith('/') ? path.slice(1) : base + path).replaceAll('\\','/').split('/');
    const result=[];
    for (const part of parts) {
        if (part === '..') { if (!result.length) throw new Error('Invalid model part path'); result.pop(); }
        else if (part && part !== '.') result.push(part);
    }
    return result.join('/');
};
function xml(text, open, close = () => {}) {
    const parser = new SaxesParser();
    parser.on('doctype', () => { throw new Error('Unsupported XML document type'); });
    parser.on('opentag', node => open(local(node.name), Object.fromEntries(Object.entries(node.attributes).map(([k,v])=>[local(k),v]))));
    parser.on('closetag', node => close(local(node.name)));
    parser.write(text).close();
}
function matrix(value) {
    if (!value) return [...identity];
    const values=value.trim().split(/\s+/).map(Number);
    if (values.length!==12 || values.some(n=>!Number.isFinite(n))) throw new Error('Invalid 3MF transform');
    return values;
}

export function parseThreeMF({ buffer }) {
    if (buffer.byteLength > MAX_MODEL_BYTES) throw new Error(TOO_LARGE_MESSAGE);
    let inflated=0;
    const files=unzipSync(new Uint8Array(buffer), { filter: entry => {
        if (!/\.model$|\.rels$|Metadata\/(model_settings|project_settings)\.config$/i.test(entry.name)) return false;
        inflated += entry.originalSize;
        if (inflated > 512*1024*1024) throw new Error('3MF expanded data exceeds the 512 MB preview limit');
        return true;
    }});
    const read = path => files[path] ? strFromU8(files[path]) : '';
    const settings=Object.create(null), plates=[];
    let owner=null, part=null, plate=null, instance=null;
    if (files['Metadata/model_settings.config']) xml(read('Metadata/model_settings.config'), (tag,a)=>{
        if (tag==='object') owner=settings[a.id]={ id:a.id, parts:Object.create(null), extruder:0, name:`Object ${a.id}` };
        if (tag==='part' && owner) part=owner.parts[a.id]={ id:a.id, subtype:a.subtype || 'normal_part', extruder:0 };
        if (tag==='plate') { plate={ id:String(plates.length+1), name:'', instances:[] }; plates.push(plate); }
        if (tag==='model_instance') instance={};
        if (tag==='metadata') {
            if (instance) instance[a.key]=a.value;
            else if (plate) {
                if (a.key==='plater_id') plate.id=a.value;
                if (a.key==='plater_name') plate.name=a.value;
            } else if (part || owner) {
                const target=part || owner;
                if (a.key==='name') target.name=a.value;
                if (a.key==='extruder') target.extruder=Number(a.value) || 0;
            }
        }
    }, tag=>{
        if (tag==='part') part=null;
        if (tag==='object') owner=null;
        if (tag==='model_instance') { plate?.instances.push(`${instance.object_id}:${instance.instance_id || 0}`); instance=null; }
        if (tag==='plate') plate=null;
    });
    const project=files['Metadata/project_settings.config'] ? JSON.parse(read('Metadata/project_settings.config')) : {};
    const palette=Array.isArray(project.filament_colour) ? project.filament_colour : [];
    let root='3D/3dmodel.model';
    if (files['_rels/.rels']) xml(read('_rels/.rels'), (tag,a)=>{
        if (tag==='Relationship' && a.Type?.endsWith('/3dmodel')) root=cleanPath(a.Target);
    });
    if (!files[root]) throw new Error('3MF root model is missing');
    const objects=Object.create(null), items=[], resources=Object.create(null);
    let faces=0;
    for (const path of Object.keys(files).filter(p=>p.endsWith('.model'))) {
        let object=null, vertices=[], positions=[], normals=[], states=[], colors=[], resource=null, build=false;
        let unit=1;
        xml(read(path), (tag,a)=>{
            if (tag==='model') {
                unit=({micron:.001,millimeter:1,centimeter:10,inch:25.4,foot:304.8,meter:1000})[a.unit || 'millimeter'];
                if (!unit) throw new Error(`Unsupported model unit: ${a.unit}`);
            }
            if (tag==='basematerials' || tag==='colorgroup') resource=resources[`${path}#${a.id}`]=[];
            if ((tag==='base' || tag==='color') && resource) resource.push(a.displaycolor || a.color);
            if (tag==='object') {
                object={id:a.id,path,name:a.name || `Object ${a.id}`,components:[],unit,pid:a.pid,pindex:Number(a.pindex || 0)};
                objects[`${path}#${a.id}`]=object;
                vertices=[]; positions=[]; normals=[]; states=[]; colors=[];
            }
            if (tag==='vertex' && object) {
                const v=[Number(a.x),Number(a.y),Number(a.z)];
                if (v.some(n=>!Number.isFinite(n))) throw new Error('Invalid 3MF vertex');
                vertices.push(v);
                if (vertices.length>3000000) throw new Error('3MF vertex limit exceeded');
            }
            if (tag==='triangle' && object) {
                const triangle=[a.v1,a.v2,a.v3].map(i=>vertices[Number(i)]);
                if (triangle.some(v=>!v)) throw new Error('3MF triangle references a missing vertex');
                const fragments=a.paint_color ? decodePaint({code:a.paint_color,triangle}) : [{triangle,state:0}];
                const material=resources[`${path}#${a.pid || object.pid}`];
                const indices=[a.p1 ?? object.pindex,a.p2 ?? a.p1 ?? object.pindex,a.p3 ?? a.p1 ?? object.pindex];
                for (const fragment of fragments) {
                    if (++faces>3000000) throw new Error('3MF exceeds the 3 million triangle preview limit');
                    const [v0,v1,v2]=fragment.triangle;
                    const u=v1.map((n,i)=>n-v0[i]), v=v2.map((n,i)=>n-v0[i]);
                    const normal=[u[1]*v[2]-u[2]*v[1],u[2]*v[0]-u[0]*v[2],u[0]*v[1]-u[1]*v[0]];
                    const length=Math.hypot(...normal) || 1;
                    for (let i=0;i<3;i++) {
                        positions.push(...fragment.triangle[i]);
                        normals.push(...normal.map(n=>n/length));
                    }
                    states.push(fragment.state);
                    colors.push(...indices.map(i=>material?.[Number(i)] || null));
                }
            }
            if (tag==='component' && object) object.components.push({id:a.objectid,path:a.path ? cleanPath(a.path,path.slice(0,path.lastIndexOf('/')+1)) : path,transform:matrix(a.transform)});
            if (tag==='build') build=true;
            if (tag==='item' && build && path===root) items.push({id:a.objectid,path,transform:matrix(a.transform),printable:a.printable!=='0',unit});
        },tag=>{
            if (tag==='object') {
                if (positions.length) Object.assign(object,{positions:new Float32Array(positions),normals:new Float32Array(normals),states:new Uint16Array(states),colors});
                object=null; vertices=[]; positions=[]; normals=[]; states=[]; colors=[];
            }
            if (tag==='basematerials' || tag==='colorgroup') resource=null;
            if (tag==='build') build=false;
        });
    }
    for (const object of Object.values(objects)) for (const child of object.components) {
        if (!objects[`${child.path}#${child.id}`]) throw new Error(`Missing model component ${child.id}`);
    }
    const counts=Object.create(null);
    for (const item of items) {
        if (!objects[`${item.path}#${item.id}`]) throw new Error(`Missing build object ${item.id}`);
        item.instance=counts[item.id] || 0; counts[item.id]=item.instance+1;
        item.key=`${item.id}:${item.instance}`;
    }
    if (!items.length || !faces) throw new Error('3MF contains no printable model geometry');
    return {objects,items,plates,settings,palette,faces,printableArea:project.printable_area || []};
}
