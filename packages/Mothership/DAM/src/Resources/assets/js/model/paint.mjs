// Bambu/Prusa facet annotation format: hex nibbles read right-to-left,
// reverse child traversal, midpoint subdivision. See the 3D preview spec.
export function decodePaint({ code, triangle }) {
    if (!/^[0-9a-f]+$/i.test(code)) throw new Error('Invalid paint encoding');
    let cursor = code.length - 1;
    const faces = [];
    const nibble = () => {
        if (cursor < 0) throw new Error('Truncated paint encoding');
        return parseInt(code[cursor--], 16);
    };
    const midpoint = (a, b) => a.map((value, i) => (value + b[i]) / 2);
    function visit(vertices, depth) {
        if (depth > 64 || faces.length > 65536) throw new Error('Paint subdivision limit exceeded');
        const value = nibble(), splits = value & 3;
        if (!splits) {
            let state = value >> 2;
            if (state === 3) {
                let extension;
                do { extension = nibble(); state += extension; } while (extension === 15);
            }
            faces.push({ triangle: vertices, state });
            return;
        }
        const side = value >> 2;
        if (side > 2 || (splits === 3 && side !== 0)) throw new Error('Invalid paint split side');
        const [a,b,c] = [0,1,2].map(i => vertices[(side+i)%3]);
        const ab = midpoint(a,b), bc = midpoint(b,c), ca = midpoint(c,a);
        const children = splits === 1 ? [[a,b,bc],[bc,c,a]]
            : splits === 2 ? [[a,ab,ca],[ab,b,ca],[b,c,ca]]
                : [[a,ab,ca],[ab,b,bc],[bc,c,ca],[ab,bc,ca]];
        for (let i=children.length-1; i>=0; i--) visit(children[i], depth+1);
    }
    visit(triangle, 0);
    if (cursor !== -1) throw new Error('Trailing paint encoding');
    return faces;
}
