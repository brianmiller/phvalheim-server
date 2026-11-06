/* Shared player mod library. Text is always rendered as text, never catalogue HTML. */
(() => {
  'use strict';
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  const dialog = el('dialog', 'pm-dialog'); dialog.id = 'playerMods'; dialog.setAttribute('aria-labelledby', 'pm-title');
  const head = el('header', 'pm-header'), heading = el('div'), eyebrow = el('p', 'pm-eyebrow', 'WORLD LIBRARY');
  const title = el('h2', '', 'Mods'); title.id = 'pm-title'; const close = el('button', 'pm-close', '×'); close.type = 'button'; close.setAttribute('aria-label', 'Close mod viewer');
  heading.append(eyebrow, title); head.append(heading, close);
  const tools = el('div', 'pm-tools'), search = el('input'); search.type = 'search'; search.placeholder = 'Search mods, authors or settings'; search.setAttribute('aria-label', search.placeholder);
  const filter = el('select'); filter.setAttribute('aria-label', 'Filter mods');
  for (const [value,label] of [['all','All mods'],['client','Player mods'],['server','Server mods'],['config','Customizable settings']]) { const o=el('option','',label);o.value=value;filter.append(o); }
  tools.append(search, filter);
  const status = el('p', 'pm-status'); status.setAttribute('role','status');
  const body = el('div', 'pm-body'), list = el('nav', 'pm-list'), detail = el('article', 'pm-detail'); list.setAttribute('aria-label','Mods');
  body.append(list, detail); dialog.append(head, tools, status, body); document.body.append(dialog);
  let data = [], selected = null, opener = null, request = null, worldName = "", csrf = "", personal = {revision:0,entries:[]}, saving = false, generation = 0;
  const key = s => JSON.stringify([s.file,s.section,s.key]);
  close.onclick = () => dialog.close();
  dialog.addEventListener('close', () => { generation++; request?.abort(); opener?.focus(); });
  dialog.addEventListener('click', e => { if (e.target === dialog) { const r = dialog.getBoundingClientRect(); if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close(); } });
  function badge(text) { return el('span','pm-badge',text); }
  function show(mod) {
    selected=mod.id; detail.replaceChildren();
    list.querySelectorAll('button').forEach(b=>b.setAttribute('aria-current',String(Number(b.dataset.id)===selected)));
    detail.append(el('p','pm-eyebrow',mod.source),el('h3','',mod.name),el('p','pm-muted','by '+mod.owner));
    const badges=el('div','pm-badges');
    badges.append(badge(Number(mod.deploy_client)?'Player install':'Server only'),badge(Number(mod.is_dep)?'Dependency':'Selected by operator'));
    if(Number(mod.deploy_server)&&Number(mod.deploy_client))badges.append(badge('Also on server'));
    if(Number(mod.is_deprecated))badges.append(badge('Deprecated'));
    detail.append(badges,el('p','pm-description',mod.description || 'No description supplied by the mod author.'));
    const facts=el('dl','pm-facts');
    for(const [label,value] of [['Installed version',mod.installed_version || mod.installation],['Selected version',mod.selected_version || 'Unavailable'],['Version policy',mod.pinned_version?'Pinned to '+mod.pinned_version:'Follow latest']])facts.append(el('dt','',label),el('dd','',value));
    detail.append(facts);
    const links=el('div','pm-links');
    for(const [label,url] of [['Mod page ↗',mod.url],['Author website ↗',mod.website]]) { if(!/^https?:\/\//i.test(url||''))continue;const a=el('a','',label);a.href=url;a.target='_blank';a.rel='noopener noreferrer';links.append(a); }
    detail.append(links);
    const settings=mod.settings || [], count=settings.filter(s=>s.overridable).length;
    detail.append(el('h4','', 'Configuration'),el('p','pm-muted',settings.length?`${count} customizable · ${settings.length-count} controlled by operator. Values below are the published defaults; your local settings may differ.`:'No player configuration has been published for this mod.'));
    detail.append(el('p','pm-editor-note','Save personal choices here to apply them on your next launch with the updated 2.0.18 client. They apply to you in this world only. “Use local config” stops applying a saved choice and leaves its last value in your local file.'));
    for(const s of settings) {
      const row=el('section','pm-setting'), top=el('div','pm-setting-heading');
      top.append(el('h5','',s.key),badge(s.overridable?'Customizable':'Operator controlled'));
      row.append(top,el('p','pm-muted',s.section+(s.type?' · '+s.type:'')));
      if(s.description)row.append(el('p','',s.description));
      if (!s.overridable) row.append(el('code','pm-value',s.value));
      else {
        const saved=personal.entries.find(e=>key(e)===key(s));
        const choices=s.acceptable?.length?s.acceptable:(s.type?.toLowerCase()==='boolean'?['true','false']:[]);
        const input=el(choices.length?'select':'input','pm-config-input');
        if(choices.length)for(const value of choices){const o=el('option','',value);o.value=value;input.append(o);}
        else {input.type='text';input.maxLength=4096;}
        input.value=saved?saved.value:s.value;
        if(s.type?.toLowerCase()==='boolean')input.value=(saved?saved.value:s.value).toLowerCase();input.setAttribute('aria-label',s.section+' · '+s.key);
        const label=el('p','pm-muted','Published default: '+s.value);
        const actions=el('div','pm-config-actions'), save=el('button','','Save my choice'), reset=el('button','','Use published default'), local=el('button','','Use local config');
        const feedback=el('p','pm-save-status',saved?'Saved for your next launch':'Using your local config');feedback.setAttribute('role','status');
        for(const b of [save,reset,local]){b.type='button';actions.append(b);}
        async function persist(value) {
          if(saving)return;
          const saveGeneration=generation;
          saving=true;actions.querySelectorAll('button').forEach(b=>b.disabled=true);feedback.textContent='Saving…';
          try {
            const response=await fetch('/api.php?mode=savePlayerConfig&world='+encodeURIComponent(worldName),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({revision:personal.revision,entries:[{file:s.file,section:s.section,key:s.key,value}]})});
            const result=await response.json();if(!response.ok)throw new Error(result.error||'Could not save.');
            if(saveGeneration!==generation)return;
            personal=result;feedback.textContent=value===null?'Using local config; its last value is kept.':'Saved. Applies on your next launch.';
            if(value!==null)input.value=value;
          } catch(error){feedback.textContent=error.message||'Could not save. Try again.';}
          finally{saving=false;actions.querySelectorAll('button').forEach(b=>b.disabled=false);}
        }
        save.onclick=()=>persist(input.value);reset.onclick=()=>persist(s.value);local.onclick=()=>persist(null);
        row.append(label,input,actions,feedback);
      }
      if(s.acceptable?.length)row.append(el('p','pm-muted','Allowed: '+s.acceptable.join(', ')));
      if(s.range)row.append(el('p','pm-muted','Range: '+(Array.isArray(s.range)?s.range.join(' – '):JSON.stringify(s.range))));
      detail.append(row);
    }
    detail.scrollTop=0;
  }
  function render() {
    const term=search.value.trim().toLowerCase();
    const shown=data.filter(m=>(filter.value==='all'||filter.value==='client'&&Number(m.deploy_client)||filter.value==='server'&&Number(m.deploy_server)||filter.value==='config'&&m.settings.some(s=>s.overridable)) && [m.name,m.owner,m.description,...m.settings.map(s=>s.key+' '+s.section+' '+s.description)].join(' ').toLowerCase().includes(term));
    list.replaceChildren();status.textContent=`${shown.length} of ${data.length} mods`;
    for(const mod of shown){const b=el('button','pm-mod');b.type='button';b.dataset.id=mod.id;b.append(el('strong','',mod.name),el('span','pm-muted',mod.owner+' · '+(mod.installed_version?'v'+mod.installed_version:mod.installation)));b.onclick=()=>show(mod);list.append(b);}
    const current=shown.find(m=>m.id===selected)||shown[0];
    if(current)show(current);else detail.replaceChildren(el('h3','','No mods found'),el('p','pm-muted',data.length?'Try another search or filter.':'The operator has not selected any catalogue mods for this world.'));
  }
  search.oninput=render; filter.onchange=render;
  document.addEventListener('click',async e=>{
    const link=e.target.closest('.mod-view-link');if(!link)return;e.preventDefault();
    const world=link.closest('[data-world]')?.dataset.world;if(!world)return;
    generation++;opener=link;worldName=world;title.textContent=world+' · Mods';search.value='';filter.value='all';data=[];selected=null;list.replaceChildren();detail.replaceChildren();status.textContent='Loading mods…';dialog.showModal();search.focus();
    request?.abort();request=new AbortController();
    try{const response=await fetch('/api.php?mode=getPlayerMods&world='+encodeURIComponent(world),{signal:request.signal,cache:'no-store'});const result=await response.json();if(!response.ok)throw new Error(result.error||'Unable to load mods.');data=result.mods;csrf=result.csrf;personal=result.personal;render();}
    catch(error){if(error.name!=='AbortError')status.textContent=error.message || 'Unable to load mods. Close and reopen to retry.';}
  });
})();
