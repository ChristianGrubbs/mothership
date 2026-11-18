const {test}=require('node:test');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const source=path.resolve(__dirname,'../../../packages/Mothership/DAM/src/Resources/widgets/rss/ticker.js');
const api=fs.existsSync(source)?require(source):{};
const now=Date.UTC(2026,8,7);
const story=(overrides={})=>({id:'1',feedId:11,source:'OpenAI News',title:'Introducing GPT-6',url:'https://openai.com/index/'+(overrides.id||'gpt-6')+'/',publishedAt:now-60000,...overrides});
test('only designated providers and announcement headlines enter the ticker',()=>{
    assert.equal(typeof api.select,'function');
    const selected=api.select({now,stories:[story(),story({id:'2',title:'Our economic impact report'}),story({id:'3',source:'The Verge'}),story({id:'4',title:'Introducing a new model',url:'javascript:alert(1)'}),story({id:'5',publishedAt:now-40*86400000}),story({id:'6',source:'Higgsfield (GNews)',url:'https://news.google.com/rss/articles/one',title:'Meet Higgsfield Genjutsu: How It Works and What You Get'}),story({id:'7',source:'Higgsfield (GNews)',title:'The 10 Best AI Image Generators in 2026'})]});
    assert.deepEqual(selected.map(s=>s.id),['1','6']);
});
test('reset alerts require an actual reset announcement; feature updates do not become model alerts',()=>{
    assert.equal(typeof api.select,'function');
    const selected=api.select({now,stories:[story({id:'a',source:'Tibo (thsottiaux)',url:'https://x.com/thsottiaux/status/1',title:'Codex usage limits will be fully reset again in the next hour'}),story({id:'b',source:'Tibo (thsottiaux)',url:'https://x.com/thsottiaux/status/2',title:'We are investigating Codex usage limits'}),story({id:'c',title:'Introducing projects in ChatGPT'}),story({id:'d',title:'Introducing GPT-6'}),story({id:'e',source:'Tibo (thsottiaux)',url:'https://x.com/someone/status/3',title:'Codex usage limits reset'})]});
    assert.deepEqual(selected.map(s=>[s.id,s.alert]),[['a','reset'],['b',null],['c',null],['d','model']]);
});
test('initial history is silent; each subsequent new release alerts once, including after navigation',()=>{
    assert.equal(typeof api.advance,'function');
    const first=api.advance({previous:null,stories:[story({alert:'model'})],now});
    assert.equal(first.fresh.length,0);
    const next=api.advance({previous:first.state,stories:[story({alert:'model'}),story({id:'2',url:'https://openai.com/new',alert:'model',publishedAt:now+1000})],now:now+2000});
    assert.equal(next.fresh.length,1);
    assert.equal(next.state.unread.length,1);
    assert.equal(api.advance({previous:JSON.parse(JSON.stringify(next.state)),stories:next.state.items,now:now+3000}).fresh.length,0);
});
test('backfilled old posts and duplicate URLs do not create fresh alerts',()=>{
    assert.equal(typeof api.advance,'function');
    const first=api.advance({previous:null,stories:[],now});
    const next=api.advance({previous:first.state,now:now+3600000,stories:[story({alert:'model'}),story({id:'2',alert:'model',publishedAt:now+1000,url:'https://openai.com/new?utm_source=rss'}),story({id:'3',alert:'model',publishedAt:now+1000,url:'https://openai.com/new'})]});
    assert.equal(next.fresh.length,1);
});
test('feed selection excludes general technology feeds and supports the scoped providers',()=>{
    assert.equal(typeof api.provider,'function');
    assert.equal(api.provider('OpenAI News'),'OpenAI');
    assert.equal(api.provider('Anthropic (GNews)'),'Anthropic');
    assert.equal(api.provider('Google DeepMind (GNews)'),'Google');
    assert.equal(api.provider('Higgsfield (GNews)'),'Higgsfield');
    assert.equal(api.provider('Tibo (thsottiaux)'),'Codex');
    assert.equal(api.provider('The Verge'),null);
});
test('official release headlines without Introducing are included but case studies and safety reports are not',()=>{
    const result=api.select({now,stories:[story({id:'a',title:'GPT-6 Astra: A new generation of intelligence'}),story({id:'b',title:'Safety overview: GPT-6 Astra'}),story({id:'c',title:'Playco cut manual fixes 50% prototyping games with GPT-6 Astra'})]});
    assert.deepEqual(result.map(s=>[s.id,s.alert]),[['a','model']]);
});
test('ticker includes the last seven days and excludes older stories',()=>{
    const result=api.select({now,stories:[story({id:'recent',publishedAt:now-6*86400000}),story({id:'old',publishedAt:now-8*86400000})]});
    assert.deepEqual(result.map(s=>s.id),['recent']);
});
test('current provider release titles do not need a launch buzzword or a hardcoded Claude model name',()=>{
    const titles=[
        ['Anthropic (GNews)','Claude Fable 5 and Claude Mythos 5 - Anthropic','model'],
        ['Google AI','Try Google Pics: Easy image creation and editing in Google Workspace',null],
        ['Higgsfield (GNews)','Higgsfield 3D Jutsu: Build and Animate 3D Scenes from a Prompt - Higgsfield AI',null],
        ['Higgsfield (GNews)','Recraft V4 Is Now on Higgsfield: V4.1, Styles, and Utility Explained - Higgsfield AI','model'],
        ['OpenAI News','Healthcare organizations can now connect EHR and additional industry data to ChatGPT',null]
    ];
    const result=api.select({now,stories:titles.map(([source,title],i)=>story({id:String(i),source,title,url:source==='OpenAI News'?'https://openai.com/feature':'https://news.google.com/rss/articles/'+i}))});
    assert.deepEqual(result.map(s=>s.alert),titles.map(t=>t[2]));
});

test('direct provider coverage includes the missed releases and unfamiliar model names',()=>{
    const now=Date.UTC(2026,8,7);
    const rows=[
        {source:'Anthropic Direct',title:'Introducing Claude Fable 5.1 and Claude Mythos 5.1',url:'https://www.anthropic.com/claude-fable-and-mythos-5-1'},
        {source:'Google DeepMind Direct',title:'Introducing WeatherNext 3, our most advanced and accurate global weather AI model',url:'https://deepmind.google/blog/weathernext-3/'},
        {source:'Google DeepMind Direct',title:'Introducing Gemini 3.8 Flash and 3.8 Flash Cyber',url:'https://deepmind.google/blog/gemini-flash/'},
        {source:'Higgsfield Direct',title:'Higgsfield 3D Jutsu: Build and Animate 3D Scenes from a Prompt',url:'https://higgsfield.ai/blog/higgsfield-3d-jutsu'}
    ].map((s,i)=>({...s,id:String(i),publishedAt:now-6*86400000}));
    const selected=api.select({stories:rows,now});
    assert.equal(selected.length,4);
    assert.equal(selected.find(s=>s.title.includes('WeatherNext')).alert,'model');
});

test('ChatGPT-prefixed and gpt-image releases are model alerts while plain ChatGPT features are not',()=>{
    const rows=[
        ['Introducing ChatGPT Images 2.5','model'],
        ['Introducing gpt-image-2.5','model'],
        ['Introducing ChatGPT 5.5','model'],
        ['Introducing projects in ChatGPT',null],
        ['Healthcare organizations can now connect EHR and additional industry data to ChatGPT',null],
        ['Introducing projects in ChatGPT for 2026',null],
        // A bare integer after ChatGPT is a count, not a version.
        ['Meet ChatGPT 3 ways to organize your work',null],
        ['Introducing ChatGPT Pro 2 new workspace features',null],
        ['Introducing ChatGPT Images 4 ways to create faster',null],
        ['Introducing ChatGPT 5-day weather forecasts',null],
        ['Introducing ChatGPT-6','model']
    ];
    const selected=api.select({now,stories:rows.map(([title],i)=>story({id:String(i),title,url:'https://openai.com/index/chatgpt-'+i+'/'}))});
    assert.deepEqual(selected.map(s=>[s.title,s.alert]),rows);
});

test('any Sottiaux reset post alerts, while negations, unrelated posts and other accounts do not',()=>{
    const post=(id,title,handle='thsottiaux')=>story({id,source:'Tibo (thsottiaux)',title,url:'https://x.com/'+handle+'/status/'+id});
    const selected=api.select({now,stories:[
        post('11','All reset for everyone. Enjoy the week with Astra.'),
        post('12','There was a bit of a kerfuffle, but banked resets are back'),
        post('13','We are investigating Codex usage limits'),
        post('14','Codex limits have not reset yet'),
        post('15','Look! A fish!'),
        post('16','All reset for everyone','someone')
    ]});
    assert.deepEqual(selected.map(s=>[s.id,s.alert]),[['11','reset'],['12','reset'],['13',null],['14',null]]);
});

test('reset negations are caught with either apostrophe, but a negation about something else still alerts',()=>{
    const post=(id,title)=>story({id,source:'Tibo (thsottiaux)',title,url:'https://x.com/thsottiaux/status/'+id});
    // Each keeps the word "Codex" so it stays visible; only the alert is under test.
    const rows=[
        ['21','Codex limits haven’t reset yet',null],
        ['22',"Codex limits haven't reset yet",null],
        ['23','Codex limits have never reset yet',null],
        ['24','Codex limits cannot reset yet',null],
        ['25','Nothing in Codex has reset yet',null],
        // Live post: the negation applies to "fully applying", not to the reset itself.
        ['26','There was a bit of a kerfuffle this morning with some banked resets not fully applying when used in ChatGPT Work and Codex.','reset']
    ];
    const selected=api.select({now,stories:rows.map(([id,title])=>post(id,title))});
    assert.deepEqual(selected.map(s=>[s.id,s.alert]),rows.map(r=>[r[0],r[2]]));
});

test('a release discovered after two offline days still alerts within the seven-day window',()=>{
    const now=Date.UTC(2026,8,7),day=86400000;
    const baseline=api.advance({previous:null,stories:[],now:now-4*day}).state;
    const story={id:'late',url:'https://openai.com/late',publishedAt:now-2*day,alert:'model'};
    assert.equal(api.advance({previous:baseline,stories:[story],now}).fresh.length,1);
});
