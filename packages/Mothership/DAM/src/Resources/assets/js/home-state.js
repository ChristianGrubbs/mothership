(() => {
    'use strict';
    window.MothershipHomeState = {
        dimensions(widget) {
            const legacy = {small:{columns:2,rows:1},wide:{columns:4,rows:1},large:{columns:8,rows:2}};
            const fallback = legacy[widget?.size] || legacy.small;
            const supplied = Number.parseInt(widget?.columns, 10);
            const columns = widget?.grid_version === 2 ? supplied : supplied * 2;
            return {
                grid_version: 2,
                columns: Math.max(1, Math.min(8, columns || fallback.columns)),
                rows: Math.max(1, Math.min(4, Number.parseInt(widget?.rows, 10) || fallback.rows)),
            };
        },
        resize({widget, columns, rows}) {
            return {
                ...widget,
                grid_version: 2,
                columns: Math.max(1, Math.min(8, Number.parseInt(columns, 10) || 1)),
                rows: Math.max(1, Math.min(4, Number.parseInt(rows, 10) || 1)),
                settings: {...widget.settings},
            };
        },
        pack(widgets, columns = 8) {
            const occupied = new Set();
            const fits = (column, row, width, height) => {
                if (column + width - 1 > columns) return false;
                for (let y=row;y<row+height;y++) for (let x=column;x<column+width;x++) if (occupied.has(`${x}:${y}`)) return false;
                return true;
            };
            return widgets.map(widget => {
                const size = this.dimensions(widget);
                const width = Math.min(columns, size.columns);
                let row = 1, column = 1;
                while (!fits(column, row, width, size.rows)) {
                    column++;
                    if (column > columns) { column=1; row++; }
                }
                for (let y=row;y<row+size.rows;y++) for (let x=column;x<column+width;x++) occupied.add(`${x}:${y}`);
                return {...widget, ...size, columns:width, column, row};
            });
        },
        // Free placement lives on the eight-column canvas only. Every function below is a pure
        // function of its input layout, so a gesture recomputed from the pre-gesture layout on each
        // pointer move can never oscillate the way incremental reordering did.
        collides(a, b) {
            return a.column < b.column + b.columns && b.column < a.column + a.columns
                && a.row < b.row + b.rows && b.row < a.row + a.rows;
        },
        // Settles items around the pinned one: anything overlapping an already settled item moves down.
        settle(items, pinnedId = null) {
            const settled = items.filter(item => item.id === pinnedId);
            const rest = items.filter(item => item.id !== pinnedId).sort((a, b) => a.row - b.row || a.column - b.column);
            for (const item of rest) {
                let hit;
                while ((hit = settled.filter(other => this.collides(item, other))).length) {
                    item.row = Math.max(...hit.map(other => other.row + other.rows));
                }
                settled.push(item);
            }
            return items;
        },
        // Resolves every widget to a cell. Saved coordinates win; widgets without them (older layouts,
        // newly added widgets) first-fit into the free cells, which for a layout with no coordinates
        // at all is exactly the previous packing.
        place(widgets) {
            const items = widgets.map(widget => {
                const size = this.dimensions(widget);
                const column = Number.parseInt(widget?.column, 10), row = Number.parseInt(widget?.row, 10);
                const fixed = column >= 1 && row >= 1;
                return {...widget, ...size, fixed,
                    column: fixed ? Math.min(column, 8 - size.columns + 1) : 0, row: fixed ? Math.min(row, 100) : 0};
            });
            const settled = this.settle(items.filter(item => item.fixed)).slice();
            for (const item of items.filter(item => !item.fixed)) {
                item.row = 1; item.column = 1;
                while (item.column + item.columns - 1 > 8 || settled.some(other => this.collides(item, other))) {
                    item.column++;
                    if (item.column + item.columns - 1 > 8) { item.column = 1; item.row++; }
                }
                settled.push(item);
            }
            return items.map(({fixed: _fixed, ...item}) => item);
        },
        layout(widgets, columns = 8) {
            return columns === 8 ? this.place(widgets) : this.pack(widgets, columns);
        },
        // Writes resolved cells back, closes fully empty rows, and keeps the array in reading order so
        // the narrower canvases (which pack from order) and the tab sequence follow the desktop layout.
        normalize(widgets) {
            const placed = this.place(widgets);
            const used = [...new Set(placed.flatMap(item => Array.from({length: item.rows}, (_, i) => item.row + i)))];
            const shift = row => used.filter(value => value < row).length + 1;
            return placed.map(item => ({...item, row: shift(item.row), settings: {...item.settings}}))
                .sort((a, b) => a.row - b.row || a.column - b.column);
        },
        unplaced(widgets) {
            return widgets.map(({column: _column, row: _row, ...widget}) => ({...widget, settings: {...widget.settings}}));
        },
        moveTo({widgets, id, column, row}) {
            const items = this.place(widgets);
            const moving = items.find(item => item.id === id);
            if (!moving) return items;
            const origin = {column: moving.column, row: moving.row};
            moving.column = Math.max(1, Math.min(8 - moving.columns + 1, Number.parseInt(column, 10) || 1));
            moving.row = Math.max(1, Math.min(100, Number.parseInt(row, 10) || 1));
            // Landing exactly on a card of the same shape trades places instead of shoving it down.
            const twin = items.find(item => item !== moving && item.column === moving.column && item.row === moving.row && item.columns === moving.columns && item.rows === moving.rows);
            if (twin) { twin.column = origin.column; twin.row = origin.row; return items; }
            return this.settle(items, id);
        },
        resizeTo({widgets, id, columns, rows}) {
            const items = this.place(widgets);
            const target = items.find(item => item.id === id);
            if (!target) return items;
            target.columns = Math.max(1, Math.min(8 - target.column + 1, Number.parseInt(columns, 10) || 1));
            target.rows = Math.max(1, Math.min(4, Number.parseInt(rows, 10) || 1));
            return this.settle(items, id);
        },
        cellAt({x, y, columnWidth, rowHeight, columnGap = 0, rowGap = 0}) {
            return {
                column: Math.round(Number(x) / Math.max(1, columnWidth + columnGap)) + 1,
                row: Math.max(1, Math.round(Number(y) / Math.max(1, rowHeight + rowGap)) + 1),
            };
        },
        poller({request, now=Date.now, visible=()=>true, interval=60000, maxBackoff=480000}) {
            let inFlight = null, lastSuccess = 0, failures = 0, retryAt = 0;
            const refresh = () => {
                if (inFlight) return inFlight;
                inFlight = Promise.resolve().then(request).then(data => {
                    lastSuccess=now();failures=0;retryAt=lastSuccess+interval;return data;
                }).catch(error => {
                    failures++;
                    retryAt=now()+Math.min(maxBackoff,interval*(2**failures));
                    throw error;
                }).finally(()=>{inFlight=null;});
                return inFlight;
            };
            return {
                refresh,
                tick() { return !visible() || now()<retryAt ? Promise.resolve(null) : refresh(); },
                snapshot() { return {lastSuccess,failures,retryAt,inFlight:Boolean(inFlight)}; },
            };
        },
        signalState() {
            const entries=new Map();let activeKey=null,generation=0;
            const empty=key=>({key,stories:[],refreshedAt:0,error:null});
            return {
                select(key) { if(key!==activeKey){activeKey=key;generation++;}if(!entries.has(key))entries.set(key,empty(key));return generation; },
                success({key,generation:token,stories,refreshedAt}) { if(key!==activeKey||token!==generation)return false;entries.set(key,{key,stories:[...stories],refreshedAt,error:null});return true; },
                failure({key,generation:token,error}) { if(key!==activeKey||token!==generation)return false;const current=entries.get(key)||empty(key);entries.set(key,{...current,error:error?.message||String(error)});return true; },
                snapshot() { const current=entries.get(activeKey)||empty(activeKey);return {...current,stories:[...current.stories]}; },
            };
        },
        feedSettings(selectedId) {
            let generation=0,active=0,loaded=false;const original=Number(selectedId)||null;
            return {
                open() { active=++generation;loaded=false;return active; },
                resolve(token) { if(token!==active)return false;loaded=true;return true; },
                fail(token) { return token===active; },
                close(token) { if(token===active)active=++generation; },
                value(selectValue) { return loaded?(Number(selectValue)||null):original; },
            };
        },
        move({widgets, id, target}) {
            const result = widgets.map(widget => ({...widget, settings: {...widget.settings}}));
            const index = result.findIndex(widget => widget.id === id);
            if (index < 0) return result;
            const [widget] = result.splice(index, 1);
            result.splice(Math.max(0, Math.min(target, result.length)), 0, widget);
            return result;
        },
        snapResize({deltaX, deltaY, columnWidth, rowHeight, columnGap=0, rowGap=0}) {
            const snap = (delta, track, gap) => Math.round(Number(delta) / Math.max(1, Number(track) + Number(gap)));
            return {columns:snap(deltaX,columnWidth,columnGap),rows:snap(deltaY,rowHeight,rowGap)};
        },
        layoutHistory(initial = []) {
            const clone = value => JSON.parse(JSON.stringify(value));
            let entries=[clone(initial)],index=0,baseline=JSON.stringify(initial);
            const current=()=>clone(entries[index]);
            return {
                current,
                commit(layout) { entries=entries.slice(0,index+1);entries.push(clone(layout));index++;return current(); },
                undo() { if(index>0)index--;return current(); },
                redo() { if(index<entries.length-1)index++;return current(); },
                saved(layout) { entries=[clone(layout)];index=0;baseline=JSON.stringify(layout);return current(); },
                snapshot() { return {canUndo:index>0,canRedo:index<entries.length-1,dirty:JSON.stringify(entries[index])!==baseline}; },
            };
        },
        acceptMessage({event, frame, origin}) {
            return event.source === frame && event.origin === origin
                && event.data?.protocol === 'mothership-widget/v1';
        },
        sessions() {
            const entries = new Map();
            return {
                active: null,
                open(id) {
                    if (!entries.has(id)) entries.set(id, {id, dirty: false});
                    this.active = id;
                    return entries.get(id);
                },
                collapse() { this.active = null; },
                markDirty(id, dirty) { if (entries.has(id)) entries.get(id).dirty = Boolean(dirty); },
                isDirty(id) { return Boolean(entries.get(id)?.dirty); },
                hasDirty() { return [...entries.values()].some(entry => entry.dirty); },
                discard(id) { entries.delete(id); if (this.active === id) this.active = null; },
            };
        },
    };
})();
