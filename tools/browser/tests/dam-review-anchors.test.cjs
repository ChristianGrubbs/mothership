const {test}=require('node:test');
const assert=require('node:assert/strict');
const path=require('node:path');
// The Comments tab's pin, time-code and mention helpers (P7). Pure functions, so no browser is needed.
const anchors=require(path.resolve(__dirname,'../../../packages/Mothership/DAM/src/Resources/assets/js/review-anchors.js'));

test('a click drops a pin and a drag marks an area that stays inside the image',()=>{
    assert.deepEqual(anchors.anchorFromDrag({x:0.25,y:0.5},{x:0.253,y:0.502}),{type:'point',x:0.25,y:0.5});
    assert.deepEqual(anchors.anchorFromDrag({x:0.8,y:0.6},{x:0.2,y:0.1}),{type:'region',x:0.2,y:0.1,w:0.6,h:0.5});
    const edge=anchors.anchorFromDrag({x:0.9,y:0.9},{x:1.4,y:2});
    assert.equal(edge.type,'region');
    assert.ok(edge.x+edge.w<=1&&edge.y+edge.h<=1);
    assert.equal(anchors.anchorFromDrag(null,{x:0.1,y:0.1}),null);
});

test('a flat or edge-squeezed drag is a pin, never a zero-size area the server would refuse',()=>{
    assert.deepEqual(anchors.anchorFromDrag({x:0.2,y:0.4},{x:0.7,y:0.4}),{type:'point',x:0.2,y:0.4});
    assert.deepEqual(anchors.anchorFromDrag({x:0.3,y:0.1},{x:0.3,y:0.9}),{type:'point',x:0.3,y:0.1});
    assert.deepEqual(anchors.anchorFromDrag({x:0.995,y:0.2},{x:1.6,y:0.8}),{type:'point',x:0.995,y:0.2});
    assert.deepEqual(anchors.anchorFromDrag({x:0.1,y:1},{x:0.6,y:1.5}),{type:'point',x:0.1,y:1});
    for(const [start,end] of [[{x:0.5,y:0.5},{x:0.505,y:0.9}],[{x:0.9,y:0.9},{x:1.4,y:2}],[{x:0,y:0},{x:-1,y:0.3}]]){
        const anchor=anchors.anchorFromDrag(start,end);
        if(anchor.type==='region')assert.ok(anchor.w>0&&anchor.h>0&&anchor.x+anchor.w<=1&&anchor.y+anchor.h<=1,JSON.stringify(anchor));
    }
});

test('pointer positions become 0-1 points and stick to the edge outside the image',()=>{
    const rect={left:100,top:50,width:200,height:100};
    assert.deepEqual(anchors.pointIn(rect,150,100),{x:0.25,y:0.5});
    assert.deepEqual(anchors.pointIn(rect,0,500),{x:0,y:1});
    assert.equal(anchors.pointIn({left:0,top:0,width:0,height:10},1,1),null);
});

test('time anchors and time codes',()=>{
    assert.deepEqual(anchors.timeAnchor(75.4321),{type:'time',start:75.432});
    assert.equal(anchors.timeAnchor(Number.NaN),null);
    assert.equal(anchors.timeAnchor(-1),null);
    assert.equal(anchors.formatTime(75.4),'1:15');
    assert.equal(anchors.formatTime(3725),'1:02:05');
    assert.equal(anchors.formatAnchorTime({type:'time',start:5,end:12}),'0:05–0:12');
    assert.equal(anchors.formatAnchorTime({type:'time',start:5}),'0:05');
    assert.equal(anchors.formatAnchorTime({type:'point',x:0,y:0}),'');
});

test('pins are numbered oldest first and skip time and unanchored comments',()=>{
    const numbers=anchors.pinNumbers([
        {id:3,created_at:'2026-10-07T10:02:00Z',anchor:{type:'region',x:0,y:0,w:0.1,h:0.1}},
        {id:1,created_at:'2026-10-07T10:00:00Z',anchor:{type:'point',x:0.5,y:0.5}},
        {id:2,created_at:'2026-10-07T10:01:00Z',anchor:{type:'time',start:3}},
        {id:4,created_at:'2026-10-07T10:03:00Z',anchor:null},
    ]);
    assert.deepEqual(numbers,{1:1,3:2});
});

test('mentions: the typed query, its replacement, dropped names and safe rendering runs',()=>{
    assert.deepEqual(anchors.mentionQuery('Hi @Al',6),{start:3,query:'Al'});
    assert.equal(anchors.mentionQuery('mail@host',9),null);
    const next=anchors.insertMention('Hi @Al there',{start:3,query:'Al'},6,'Alice Doe');
    assert.deepEqual(next,{text:'Hi @Alice Doe  there',caret:14});
    const people=[{id:7,name:'Alice Doe'},{id:8,name:'Bob'},{id:7,name:'Alice Doe'}];
    assert.deepEqual(anchors.activeMentions('cc @Alice Doe',people),[{id:7,name:'Alice Doe'}]);
    assert.deepEqual(anchors.segments('cc @Alice Doe <b>x</b>',[{id:7,name:'Alice Doe'}]),[
        {text:'cc ',mention:false},
        {text:'@Alice Doe',mention:true},
        {text:' <b>x</b>',mention:false},
    ]);
});
