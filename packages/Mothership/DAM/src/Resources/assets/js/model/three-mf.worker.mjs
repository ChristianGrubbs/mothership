import { parseThreeMF } from './three-mf.mjs';
self.onmessage = ({data}) => {
    try {
        const result=parseThreeMF({buffer:data.buffer});
        const transfers=Object.values(result.objects).flatMap(o=>o.positions ? [o.positions.buffer,o.normals.buffer,o.states.buffer] : []);
        self.postMessage({result},transfers);
    } catch (error) { self.postMessage({error:error.message || 'Unable to read 3MF model'}); }
};
