const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const root=path.join(__dirname,'../xiaoman-inquiry-center');
const form='<form class="brxe-form" data-xi-inquiry="quick" data-xifr-form="1" data-xifr-field="Message" data-xifr-page-required="false"><input name="FullName"><textarea name="Message"></textarea><button type="submit">Submit</button></form>';
const links='<a id="product" href="/products/x/">x</a><a id="lang" href="https://www.fixture.example.invalid/fr/products/x/">fr</a><a id="external" href="https://other.example/">o</a><a id="anchor" href="#top">t</a><a id="popup" href="/products/x/#brx-popup-1">p</a><a id="file" href="/files/catalog.pdf">pdf</a><a id="admin" href="/wp-admin/">a</a><a id="self" href="/contact/">self</a><a id="download" href="/products/x/" download>d</a>';
function page(suffix='',choice='rejected',extra={}){
 const dom=new JSDOM(form+links+(extra.html||''),{url:'https://fixture.example.invalid/contact/'+suffix,referrer:extra.referrer,runScripts:'outside-only'});const w=dom.window;
 w.XIContextConfig={host:'fixture.example.invalid',service:'xiaoman-inquiry-attribution'};w.XIFRConfig={fieldName:'Message',minLength:1,campaignCodes:['pm']};
 w.__consent=choice;
 w.consentApi={consentSync(){return w.__consent==='missing'?{cookie:null}:{cookie:{id:1},consentGiven:true,cookieOptIn:w.__consent==='accepted'};}};
 for(const f of ['form-context.js','required/conditional-required.js'])w.eval(fs.readFileSync(path.join(root,f),'utf8'));
 w.document.dispatchEvent(new w.Event('DOMContentLoaded'));return dom;
}
function packet(dom){return JSON.parse(dom.window.document.querySelector('[name="xi_context"]').value);}
function press(dom,id,type='mousedown'){const a=dom.window.document.getElementById(id);a.dispatchEvent(new dom.window.MouseEvent(type,{bubbles:true}));return a.getAttribute('href');}
function carriedFrom(href){const h=new URL(href).hash;assert.ok(h.startsWith('#xi='),href);return new URLSearchParams(decodeURIComponent(h.slice(4)));}
function mark(query){return '#xi='+encodeURIComponent(query);}
const adLanding='?campaign=sefk-ds-la03&campaignid=24097560911&adgroup=123&device=m&keyword=tractor&loc=1005402&gclid=CLICK-ID&gbraid=BRAID';

test('without consent internal links carry campaign codes in the fragment, never click IDs, and nothing is stored',()=>{
 const d=page(adLanding);const q=carriedFrom(press(d,'product'));
 assert.equal(q.get('campaign'),'sefk-ds-la03');assert.equal(q.get('adgroup'),'123');assert.equal(q.get('keyword'),'tractor');assert.equal(q.get('device'),'m');assert.equal(q.get('loc'),'1005402');
 assert.ok(Math.abs(Number(q.get('t'))-Date.now())<5000);
 for(const k of ['gclid','gbraid','wbraid','fbclid','msclkid','ttclid','dclid'])assert.equal(q.has(k),false,k);
 assert.ok(!press(d,'product').includes('CLICK-ID'));
 assert.ok(carriedFrom(press(d,'lang','touchstart')).get('campaign'),'www and language links are internal');
 assert.equal(d.window.localStorage.length,0);assert.equal(d.window.sessionStorage.length,0);d.window.close();
});
test('external links, page anchors, popups, files, admin, downloads and the same page are left alone',()=>{
 const d=page(adLanding);
 assert.equal(press(d,'external'),'https://other.example/');assert.equal(press(d,'anchor'),'#top');assert.equal(press(d,'popup'),'/products/x/#brx-popup-1');
 assert.equal(press(d,'file'),'/files/catalog.pdf');assert.equal(press(d,'admin'),'/wp-admin/');assert.equal(press(d,'download'),'/products/x/');
 assert.ok(carriedFrom(press(d,'self')).get('campaign'),'same path without the ad parameters is a real navigation');d.window.close();
 const c=page(mark('campaign=sefk-ds-la03&t='+Date.now()));assert.equal(press(c,'self'),'/contact/','a same-document link would leave the marker in the address bar');c.window.close();
});
test('the next page reads carried codes, removes the marker and fills the campaign without storage',()=>{
 const d=page(mark('campaign=sefk-ds-la03&adgroup=123&device=m&loc=1005402&t='+Date.now()));const p=packet(d);
 assert.equal(p.A1,'sefk-ds-la03');assert.equal(p.A3,'123');assert.equal(p.A4,'m');assert.equal(p.A6,'1005402');assert.equal(p.A5,'');
 assert.ok(p._request.landing_ad_url.includes('campaign=sefk-ds-la03'));assert.equal(p._request.consent_status,'rejected');assert.equal(p._tracking.carried,'previous_page_codes');
 assert.equal(p.H1,'https://fixture.example.invalid/contact/');assert.equal(d.window.location.hash,'');
 assert.equal(d.window.localStorage.length,0);assert.equal(d.window.sessionStorage.length,0);
 assert.equal(carriedFrom(press(d,'product')).get('campaign'),'sefk-ds-la03','the chain continues to the following page');d.window.close();
});
test('expired, future, malformed and placeholder codes are ignored; injected click IDs are dropped',()=>{
 for(const h of [mark('campaign=old&t='+(Date.now()-121*60000)),mark('campaign=late&t='+(Date.now()+3600000)),'#xi=%E0%A4%A',mark('campaign=x'),mark('campaign={campaignid}&t='+Date.now())]){
  const d=page(h);assert.equal(packet(d).A1,'',h);assert.equal(d.window.location.hash,'',h);assert.equal(press(d,'product'),'/products/x/',h);d.window.close();
 }
 const d=page(mark('campaign=ok-code&gclid=INJECTED&t='+Date.now()));assert.equal(packet(d).A1,'ok-code');assert.equal(packet(d).A5,'');
 assert.equal(carriedFrom(press(d,'product')).has('gclid'),false);d.window.close();
 const o=page('#top');assert.equal(o.window.location.hash,'#top','other fragments stay');assert.equal(packet(o).A1,'');o.window.close();
});
test('campaign codes in the page URL win over carried codes',()=>{
 const d=page('?campaign=new-code'+mark('campaign=old-code&t='+Date.now()));assert.equal(packet(d).A1,'new-code');assert.equal(d.window.location.hash,'');
 assert.equal(carriedFrom(press(d,'product')).get('campaign'),'new-code');d.window.close();
});
test('a click ID passed in the URL keeps its own campaign codes from the fragment of the same tab',()=>{
 const d=page('?gclid=SAME-CLICK'+mark('campaign=sefk-gn-eu&t='+Date.now()));assert.equal(packet(d).A1,'sefk-gn-eu');assert.equal(packet(d).A5,'SAME-CLICK');d.window.close();
});
test('consented visitors keep the history path and are not decorated',()=>{
 const d=page(adLanding,'accepted');assert.equal(press(d,'product'),'/products/x/');assert.equal(packet(d).A1,'sefk-ds-la03');assert.ok(d.window.localStorage.getItem('xi_marketing_v1'));d.window.close();
});
test('consent given on the next page keeps the carried campaign in history',async()=>{
 const d=page(mark('campaign=setr-cp-fr&adgroup=7&device=c&loc=1006094&t='+Date.now()));assert.equal(packet(d).A1,'setr-cp-fr');
 d.window.__consent='accepted';d.window.document.dispatchEvent(new d.window.Event('RCB/OptIn'));await new Promise(r=>setTimeout(r,5));
 const p=packet(d);assert.equal(p.A1,'setr-cp-fr');assert.equal(p._request.consent_status,'accepted');
 assert.ok(d.window.localStorage.getItem('xi_marketing_v1').includes('campaign=setr-cp-fr'));assert.equal(press(d,'product'),'/products/x/','no decoration after consent');d.window.close();
});
test('a PM campaign carried to the next page keeps the message requirement',()=>{
 const d=page(mark('campaign=pmtr-gn-la&device=m&t='+Date.now()));d.window.document.querySelector('textarea').dispatchEvent(new d.window.FocusEvent('focusin',{bubbles:true}));
 assert.equal(d.window.document.querySelector('textarea').required,true);d.window.close();
});
test('the consent service being missing is treated like no consent',()=>{
 const d=page(adLanding,'missing');assert.equal(carriedFrom(press(d,'product')).get('campaign'),'sefk-ds-la03');assert.equal(d.window.localStorage.length,0);d.window.close();
});
test('a tagged page on this site is read from the referrer when the landing page never ran its script',()=>{
 const landing='https://fixture.example.invalid/products/'+adLanding;
 const d=page('','rejected',{referrer:landing});const p=packet(d);
 assert.equal(p.A1,'sefk-ds-la03');assert.equal(p.A3,'123');assert.equal(p.A5,'','click IDs in the referrer are never taken');assert.equal(p._tracking.carried,'previous_page_codes');
 assert.ok(p._request.landing_ad_url.includes('campaign=sefk-ds-la03'));assert.ok(!p._request.landing_ad_url.includes('CLICK-ID'));assert.ok(!JSON.stringify(p).includes('BRAID'));
 assert.equal(d.window.localStorage.length,0);assert.equal(carriedFrom(press(d,'product')).get('campaign'),'sefk-ds-la03','the chain continues');d.window.close();
});
test('a consented visitor opened from a landing page that never ran gets the campaign into history',()=>{
 const d=page('','accepted',{referrer:'https://fixture.example.invalid/products/'+adLanding});assert.equal(packet(d).A1,'sefk-ds-la03');
 assert.ok(d.window.localStorage.getItem('xi_marketing_v1').includes('campaign=sefk-ds-la03'));assert.ok(!d.window.localStorage.getItem('xi_marketing_v1').includes('CLICK-ID'));d.window.close();
});
test('a Google-decorated next page keeps its own click ID and takes the codes from the referrer',()=>{
 const d=page('?_gl=1*abc&gclid=SAME-CLICK','accepted',{referrer:'https://fixture.example.invalid/products/?campaign=pmfk-el-br&device=m&loc=1&gclid=SAME-CLICK'});const p=packet(d);
 assert.equal(p.A1,'pmfk-el-br');assert.equal(p.A5,'SAME-CLICK');d.window.close();
});
test('referrers from other sites, untagged referrers and own URL codes are respected',()=>{
 const a=page('','rejected',{referrer:'https://www.google.com/search?campaign=evil&gclid=x'});assert.equal(packet(a).A1,'');a.window.close();
 const b=page('','rejected',{referrer:'https://fixture.example.invalid/products/'});assert.equal(packet(b).A1,'');assert.equal(packet(b)._tracking.carried,undefined);b.window.close();
 const c=page('?campaign=new-code','rejected',{referrer:'https://fixture.example.invalid/products/?campaign=old-code'});assert.equal(packet(c).A1,'new-code');c.window.close();
 const e=page(mark('campaign=from-hash&t='+Date.now()),'rejected',{referrer:'https://fixture.example.invalid/products/?campaign=from-ref'});assert.equal(packet(e).A1,'from-hash','the marker is the later evidence');e.window.close();
});
test('AJAX pagination: the snapshot follows the address, and its links are not decorated',()=>{
 const d=page(adLanding,'rejected',{html:'<div class="brxe-pagination brx-ajax-pagination"><a id="next" class="page-numbers" href="/contact/page/2/">2</a></div>'});
 assert.equal(press(d,'next'),'/contact/page/2/');
 d.window.history.pushState({},'','/contact/page/2/');d.window.document.querySelector('textarea').dispatchEvent(new d.window.FocusEvent('focusin',{bubbles:true}));
 const p=packet(d);assert.ok(p._request.landing_ad_url.startsWith('https://fixture.example.invalid/contact/page/2/?'),p._request.landing_ad_url);assert.ok(p._request.landing_ad_url.includes('campaign=sefk-ds-la03'));assert.equal(p.A1,'sefk-ds-la03');assert.equal(p.H1,'https://fixture.example.invalid/contact/page/2/');
 assert.ok(carriedFrom(press(d,'product')).get('campaign'),'ordinary links on the new page still carry');d.window.close();
});
test('a marker on the thank-you redirect keeps the query, is removed and carries on to the next page',()=>{
 const d=page('?form=quick_quote&inquiry_id=FW-N7&form_type=detail'+mark('campaign=pmfk-el-br&device=m&loc=1&t='+Date.now()));
 assert.equal(d.window.location.hash,'');assert.equal(d.window.location.search,'?form=quick_quote&inquiry_id=FW-N7&form_type=detail');
 assert.equal(carriedFrom(press(d,'product')).get('campaign'),'pmfk-el-br');d.window.close();
});
// beta.21: the entry page's source rides along with the codes (origin only, or 'direct'), for two hours after each click.
const inside={referrer:'https://fixture.example.invalid/products/'};
test('without consent the entry page carries where the visit came from: an external origin only, or direct',()=>{
 const d=page('','rejected',{referrer:'https://www.google.com/search?q=mini+excavator'});const q=carriedFrom(press(d,'product'));
 assert.equal(q.get('src'),'https://www.google.com');assert.equal(q.has('campaign'),false);assert.equal(q.has('q'),false);assert.ok(Math.abs(Number(q.get('t'))-Date.now())<5000);
 assert.equal('entry_referrer' in packet(d)._request,false,'the entry page itself is labelled from its own referrer');
 assert.equal(d.window.localStorage.length,0);assert.equal(d.window.sessionStorage.length,0);d.window.close();
 const e=page('','rejected');assert.equal(carriedFrom(press(e,'product')).get('src'),'direct','no referrer = opened directly');e.window.close();
 const f=page(adLanding,'rejected',{referrer:'https://www.google.com/'});const g=carriedFrom(press(f,'product'));
 assert.equal(g.get('src'),'https://www.google.com');assert.equal(g.get('campaign'),'sefk-ds-la03');assert.equal(g.has('gclid'),false);f.window.close();
 const h=page('','rejected',{referrer:'android-app://com.google.android.gm/'});assert.equal(carriedFrom(press(h,'product')).get('src'),'direct','an app referrer is no web source');h.window.close();
});
test('a later page sends the carried entry source, keeps the chain going and stores nothing',()=>{
 const d=page(mark('src=https://www.google.com&t='+Date.now()),'rejected',inside);const p=packet(d);
 assert.equal(p._request.entry_referrer,'https://www.google.com/');assert.equal(p._tracking.carried_source,'previous_page_source');assert.equal(p.A1,'');
 assert.equal(p._request.referrer,'https://fixture.example.invalid/products/','the previous page is still reported');assert.equal(d.window.location.hash,'');
 assert.equal(carriedFrom(press(d,'product')).get('src'),'https://www.google.com');assert.equal(carriedFrom(press(d,'lang')).get('src'),'https://www.google.com','language links carry it too');
 assert.equal(d.window.localStorage.length,0);assert.equal(d.window.sessionStorage.length,0);d.window.close();
 const e=page(mark('src=direct&t='+Date.now()),'rejected',inside);assert.equal(packet(e)._request.entry_referrer,'','opened directly');e.window.close();
 const f=page('','rejected',inside);assert.equal('entry_referrer' in packet(f)._request,false,'no marker, nothing claimed');assert.equal(press(f,'product'),'/products/x/');f.window.close();
});
test('the carried codes and source last two hours after the last click',()=>{
 const ok=page(mark('campaign=pmex-cp-eu&gad_source=1&src=https://www.google.com&t='+(Date.now()-85*60000)),'rejected',inside);
 assert.equal(packet(ok).A1,'pmex-cp-eu');assert.equal(packet(ok)._request.entry_referrer,'https://www.google.com/');
 const q=carriedFrom(press(ok,'lang'));assert.equal(q.get('campaign'),'pmex-cp-eu');assert.ok(Math.abs(Number(q.get('t'))-Date.now())<5000,'each click restarts the two hours');ok.window.close();
 const old=page(mark('campaign=pmex-cp-eu&src=https://www.google.com&t='+(Date.now()-121*60000)),'rejected',inside);
 assert.equal(packet(old).A1,'');assert.equal('entry_referrer' in packet(old)._request,false);assert.equal(press(old,'product'),'/products/x/');old.window.close();
 const late=page(mark('campaign=pmex-cp-eu&src=https://www.google.com&t='+(Date.now()-85*60000)),'rejected',inside);const now=Date.now();
 late.window.Date.now=()=>now+40*60000;late.window.document.querySelector('textarea').dispatchEvent(new late.window.FocusEvent('focusin',{bubbles:true}));
 assert.equal(packet(late).A1,'','two hours passed on this page');assert.equal('entry_referrer' in packet(late)._request,false);late.window.close();
});
test('only an external origin or "direct" is accepted as a carried source',()=>{
 for(const s of ['javascript:alert(1)','https://fixture.example.invalid','https://www.fixture.example.invalid','https://www.google.com/search?q=x','https://user@evil.example','https://evil.example:8443','ftp://files.example','x'.repeat(300),'']){
  const d=page(mark('src='+encodeURIComponent(s)+'&t='+Date.now()),'rejected',inside);
  assert.equal('entry_referrer' in packet(d)._request,false,s);assert.equal(press(d,'product'),'/products/x/',s);d.window.close();
 }
});
test('consented visitors send no carried source; consent given on the next page takes the carried source into history',async()=>{
 const a=page('','accepted',{referrer:'https://www.google.com/'});assert.equal(press(a,'product'),'/products/x/');assert.equal('entry_referrer' in packet(a)._request,false);a.window.close();
 const d=page(mark('src=https://www.google.com&t='+Date.now()),'rejected',inside);
 d.window.__consent='accepted';d.window.document.dispatchEvent(new d.window.Event('RCB/OptIn'));await new Promise(r=>setTimeout(r,5));
 const p=packet(d);assert.equal(p._request.consent_status,'accepted');assert.equal('entry_referrer' in p._request,false);
 assert.equal(JSON.parse(d.window.localStorage.getItem('xi_first_v1')).ref,'https://www.google.com/');assert.equal(p.R1,'https://www.google.com/');assert.equal(p.R3,'https://www.google.com/');
 assert.equal(p._source.ref,'https://www.google.com/');assert.equal(press(d,'product'),'/products/x/','no decoration after consent');d.window.close();
});
