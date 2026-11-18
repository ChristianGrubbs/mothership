(() => {
    'use strict';

    window.MothershipFavoriteState = {
        client({request}) {
            const values = new Map();
            const waiters = new Map();
            const queued = new Set();
            const toggleQueues = new Map();
            let scheduled = false;

            const flush = async () => {
                scheduled = false;
                const ids = [...queued];
                queued.clear();
                try {
                    const responses = await Promise.all(Array.from({length:Math.ceil(ids.length/100)},(_,index)=>request({method:'GET',ids:ids.slice(index*100,(index+1)*100)})));
                    const favorites = Object.assign({}, ...responses.map(data=>data.favorites||{}));
                    for (const id of ids) {
                        const favorite = Boolean(favorites[id]);
                        values.set(id, favorite);
                        waiters.get(id)?.resolve(favorite);
                        waiters.delete(id);
                    }
                } catch (error) {
                    for (const id of ids) {
                        waiters.get(id)?.reject(error);
                        waiters.delete(id);
                    }
                }
            };

            return {
                has(id) {
                    id = Number(id);
                    if (values.has(id)) return Promise.resolve(values.get(id));
                    if (waiters.has(id)) return waiters.get(id).promise;
                    let resolve, reject;
                    const promise = new Promise((yes, no) => { resolve=yes; reject=no; });
                    waiters.set(id, {promise,resolve,reject});
                    queued.add(id);
                    if (!scheduled) { scheduled=true; queueMicrotask(flush); }
                    return promise;
                },
                async toggle(id) {
                    id = Number(id);
                    const previous = toggleQueues.get(id) || Promise.resolve();
                    const operation = previous.catch(()=>{}).then(async () => {
                        const favorite = !await this.has(id);
                        await request({method:favorite?'POST':'DELETE', id});
                        values.set(id, favorite);
                        return favorite;
                    });
                    toggleQueues.set(id, operation);
                    operation.then(()=>{if(toggleQueues.get(id)===operation)toggleQueues.delete(id);},()=>{if(toggleQueues.get(id)===operation)toggleQueues.delete(id);});
                    return operation;
                },
            };
        },
        selection(client) {
            const state = {id:null,favorite:false,error:null,generation:0};
            const selection = {
                select(id) {
                    const generation=++state.generation;
                    state.id=Number(id);state.favorite=false;state.error=null;
                    return generation;
                },
                async load(id) {
                    const generation=this.select(id);
                    try {
                        const favorite=await client.has(state.id);
                        if(generation===state.generation)state.favorite=favorite;
                    } catch(error) {
                        if(generation===state.generation)state.error=error.message||String(error);
                    }
                },
                async toggle() {
                    const id=state.id,generation=state.generation;
                    state.error=null;
                    try {
                        const favorite=await client.toggle(id);
                        if(generation===state.generation&&id===state.id)state.favorite=favorite;
                    } catch(error) {
                        if(generation===state.generation&&id===state.id)state.error=error.message||String(error);
                    }
                },
                snapshot() { return {...state}; },
            };
            return selection;
        },
    };
})();
