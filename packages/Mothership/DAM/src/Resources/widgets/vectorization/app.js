(() => {
    'use strict';
    const root=document.getElementById('vectorization');if(!root||root.dataset.ready)return;root.dataset.ready='1';
    const $=name=>root.querySelector(`[data-${name}]`),lifecycle=new AbortController();
    let snapshot=null,file=null,requestId=null,uploading=false,originalUrl=null,selectedId=null,svgText='',timer=null,epoch=0;
    const say=message=>{$('status').textContent=message;};
    const canSpend=()=>snapshot?.ready && (snapshot.allowance.unlimited||snapshot.allowance.remaining>0);
    const buttons=()=>{$('submit-button').disabled=uploading||!file||!canSpend();$('input').disabled=uploading;};
    async function json(url,options={}) {
        const response=await fetch(url,{credentials:'same-origin',signal:lifecycle.signal,...options,
            headers:{Accept:'application/json','X-CSRF-TOKEN':root.dataset.csrf,...options.headers}});
        const data=await response.json().catch(()=>({}));
        if(!response.ok)throw Error(data.message||`Request failed (${response.status}).`);return data;
    }
    async function select(result) {
        if(result.status!=='completed')return;
        const current=++epoch;selectedId=result.id;svgText='';$('copy').disabled=true;$('copy-fallback').hidden=true;
        $('result').hidden=false;$('svg').href=result.svg_url;$('pdf').href=result.pdf_url;
        $('preview').src=result.svg_url+'?preview=1';$('zoom').value='100';$('zoom-value').textContent='100%';$('preview').style.width='100%';
        try {
            const response=await fetch(result.svg_url,{credentials:'same-origin',signal:lifecycle.signal});
            if(!response.ok)throw Error('SVG could not be loaded. Use Download SVG to retry.');
            const text=await response.text();if(current!==epoch)return;
            svgText=text;$('copy').disabled=false;
        }catch(error){if(error.name!=='AbortError'&&current===epoch)say(error.message);}
    }
    function render() {
        const balance=snapshot.allowance;
        $('balance').textContent=balance.unlimited?'Unlimited vectorizations':`${balance.remaining} available · ${balance.reserved} in progress`;
        if(!snapshot.ready)say('Image Vectorization is not connected.');
        else if(!balance.unlimited&&balance.remaining===0&&!balance.reserved)say('No vectorizations remaining. Ask your administrator for an allocation.');
        const history=$('history');history.replaceChildren();
        for(const result of snapshot.results) {
            const button=document.createElement('button');button.type='button';
            button.textContent=`${result.created_at} · ${result.status}`;button.disabled=result.status!=='completed';
            button.onclick=()=>{if(originalUrl){URL.revokeObjectURL(originalUrl);originalUrl=null;$('original').hidden=true;}select(result);};history.append(button);
        }
        const selected=snapshot.results.find(result=>result.id===selectedId);
        if(selected?.status==='completed'&&!svgText){select(selected);say('Your vector files are ready.');}
        else if(selected?.status==='failed')say(selected.message);
        buttons();
    }
    async function refresh() {
        clearTimeout(timer);
        try {
            snapshot=await json(root.dataset.state);render();
            if(snapshot.results.some(result=>['queued','processing'].includes(result.status)))timer=setTimeout(refresh,2500);
        }catch(error){if(error.name!=='AbortError'){say(error.message);timer=setTimeout(refresh,5000);}}
    }
    $('input').onchange=()=>{
        file=$('input').files[0]||null;requestId=file?crypto.randomUUID():null;
        if(originalUrl)URL.revokeObjectURL(originalUrl);originalUrl=null;$('original').hidden=true;$('original').removeAttribute('src');
        if(file&&file.size>20*1024*1024){file=null;say('Choose an image up to 20 MiB.');}
        if(file){originalUrl=URL.createObjectURL(file);$('original').src=originalUrl;$('original').hidden=false;say('Ready to vectorize.');}
        buttons();
    };
    $('submit-button').onclick=async()=>{
        if(uploading||!file||!canSpend())return;
        uploading=true;++epoch;selectedId=null;svgText='';$('result').hidden=true;buttons();say('Submitting image…');
        const body=new FormData();body.append('image',file);body.append('request_id',requestId);
        try {
            const data=await json(root.dataset.submit,{method:'POST',body});selectedId=data.result.id;svgText='';
            file=null;$('input').value='';requestId=null;say('Vectorizing… You can return to this tool while it finishes.');await refresh();
        }catch(error){if(error.name!=='AbortError')say(error.message+' You can retry this submission.');}
        finally{uploading=false;buttons();}
    };
    $('zoom').oninput=()=>{const value=Number($('zoom').value);$('preview').style.width=value+'%';$('zoom-value').textContent=value+'%';};
    $('copy').onclick=async()=>{
        if(!svgText)return;
        try{await navigator.clipboard.writeText(svgText);say('SVG code copied.');}
        catch{const field=$('code');field.value=svgText;$('copy-fallback').hidden=false;field.focus();field.select();say('Select and copy the SVG code below.');}
    };
    window.addEventListener('pagehide',()=>{lifecycle.abort();clearTimeout(timer);if(originalUrl)URL.revokeObjectURL(originalUrl);},{once:true});
    async function loadAsset() {
        const assetId=new URL(window.location.href).searchParams.get('asset');
        if(!assetId)return;
        if(!/^[1-9][0-9]*$/.test(assetId)){say('This asset link is invalid. Choose an image below.');return;}
        uploading=true;buttons();say('Loading asset…');
        try {
            const data=await json(root.dataset.asset.replace('__ASSET__',assetId));
            if(!data.success||!['png','jpg','jpeg','webp','bmp','gif','tif','tiff'].includes((data.asset?.extension||'').toLowerCase()))throw Error('Choose a supported raster image.');
            if(Number(data.asset.file_size)>20*1024*1024)throw Error('Choose an image up to 20 MiB.');
            const url=new URL(root.dataset.source.replace('__ASSET__',assetId),window.location.href);
            if(url.origin!==window.location.origin)throw Error('The original image could not be loaded.');
            const response=await fetch(url,{credentials:'same-origin',signal:lifecycle.signal});
            if(!response.ok)throw Error('The original image could not be loaded.');
            const blob=await response.blob();
            if(blob.size>20*1024*1024)throw Error('Choose an image up to 20 MiB.');
            const transfer=new DataTransfer();
            transfer.items.add(new File([blob],data.asset.file_name,{type:blob.type}));
            $('input').files=transfer.files;$('input').onchange();
            say(`${data.asset.display_name||data.asset.file_name} is ready to vectorize.`);
        }catch(error){if(error.name!=='AbortError')say(error.message.replace(/[.!?]$/, '')+'. Choose an image to continue.');}
        finally{uploading=false;buttons();}
    }
    refresh();
    loadAsset();
})();
