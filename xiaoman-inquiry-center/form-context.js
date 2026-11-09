/* Plugin-owned request context. Retained history is read/written only after consent. */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else api.start(root);
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';
  var DAY = 86400000, IDLE = 30 * 60000;
  var CFG=(typeof window!=='undefined' && window.XIContextConfig)||{}; var SERVICE=CFG.service||'xiaoman-inquiry-attribution';
  var FIRST='xi_first_v1',VISIT='xi_visit_v1',MARKETING='xi_marketing_v1',SESSION='xi_session_ad_v1';
  // A single plugin-owned packet replaces builder HD fields. Native Custom gates CRM.
  var FORM = 'form.brxe-form[data-xi-inquiry]';
  var KEYS = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id','utm_adgroup','utm_device','utm_matchtype','utm_network','utm_creative','campaign','campaign_name','campaign_id','campaignid','adgroup','adgroup_name','adgroup_id','adgroupid','adset_name','adset_id','ad','ad_name','ad_id','creative_id','adid','creative','keyword','kw','device','loc','loc_physical_ms','gclid','fbclid','gbraid','wbraid','ttclid','dclid','msclkid','matchtype','mt','network','placement','targetid','target_id'];
  // Without consent only campaign-structure codes may follow an internal link, in the URL fragment:
  // never a click ID, nothing stored, and the fragment never reaches the server or the page cache.
  var CLICK_IDS = ['gclid','fbclid','gbraid','wbraid','ttclid','dclid','msclkid'];
  var CARRY = KEYS.filter(function (k) { return CLICK_IDS.indexOf(k) < 0; }), MARK = '#xi=';
  function text(v, n) { return typeof v === 'string' ? v.replace(/[\u0000-\u001f\u007f]/g, '').trim().slice(0, n || 500) : ''; }
  function codes(params) {
    var out = {};
    CARRY.forEach(function (k) { var v = text(params.get(k), 200); if (v && !/[{}]|__[A-Z_]+__/.test(v)) out[k] = v; });
    return Object.keys(out).length ? out : null;
  }
  // '#xi=' + encoded codes and the click time; valid for one visit window after that click.
  function carriedCodes(hash, now) {
    if (typeof hash !== 'string' || hash.indexOf(MARK) !== 0 || hash.length > 2000) return null;
    try {
      var q = new URLSearchParams(decodeURIComponent(hash.slice(MARK.length))), at = Number(q.get('t'));
      if (!Number.isFinite(at) || at > now + 60000 || now - at >= IDLE) return null;
      var found = codes(q); return found ? {codes:found, at:at} : null;
    } catch (_) { return null; }
  }
  // The page this one was opened from, when it is on this site: covers a landing page left before its scripts ran.
  function referrerCodes(ref, href) {
    if (!ref || !internal(ref, href)) return null;
    try { return codes(new URL(ref).searchParams); } catch (_) { return null; }
  }
  function carryHash(found, now) {
    var q = new URLSearchParams(); Object.keys(found).forEach(function (k) { q.set(k, found[k]); }); q.set('t', String(now));
    return MARK + encodeURIComponent(q.toString());
  }
  function withCodes(href, found) {
    var u = new URL(href); u.hash = '';
    Object.keys(found).forEach(function (k) { if (!u.searchParams.has(k)) u.searchParams.set(k, found[k]); });
    return u.href;
  }
  function url(v, params) {
    try {
      var u = new URL(v); if (!/^https?:$/.test(u.protocol) || u.username || u.password || u.port) return '';
      var q = new URLSearchParams();
      if (params) KEYS.forEach(function (k) { var x = text(u.searchParams.get(k)); if (x && !/[{}]|__[A-Z_]+__/.test(x)) q.set(k, x); });
      var safe = u.origin + u.pathname + (q.toString() ? '?' + q : '');
      return safe.length <= 16000 ? safe : '';
    } catch (_) { return ''; }
  }
  function internal(v, here) {
    try { return new URL(v).hostname.replace(/^www\./, '') === new URL(here).hostname.replace(/^www\./, ''); } catch (_) { return false; }
  }
  function ads(href, ref) {
    var p = new URL(href).searchParams;
    function pick(list) { for (var k of list) { var v = text(p.get(k)); if (v && !/[{}]|__[A-Z_]+__/.test(v)) return v; } return ''; }
    var source = pick(['utm_source']).toLowerCase();
    var fb = /^(facebook|fb|instagram|ig|meta)$/.test(source) || (!source && (p.has('fbclid') || /https?:\/\/([^/]+\.)?(facebook|instagram)\.com\//i.test(ref)));
    return {
      A1: pick(['campaign_name','campaign','utm_campaign','campaign_id','campaignid','utm_id']),
      A2: fb ? '' : pick(['keyword','utm_term','kw']),
      A3: pick(['adgroup_name','adset_name','adgroup','adgroup_id','adgroupid','adset_id','utm_adgroup']),
      A4: pick(['device','utm_device']),
      A5: fb ? pick(['fbclid']) : pick(['gclid','fbclid']),
      A6: pick(['loc','loc_physical_ms']),
      A7: pick(['ad_name','ad','utm_content','ad_id','creative_id','adid','creative','utm_creative'])
    };
  }
  function entry(href, ref, now) { return { ref: internal(ref, href) ? '' : url(ref), landing: url(href, true), ad: ads(href, ref), at: now }; }
  // Marketing retention is independent of the 30-minute visit boundary. Keep one
  // complete URL snapshot: never attach an old campaign to a newer click ID.
  function marketingRecord(value, now) {
    if (!value || !Number.isFinite(value.at) || value.at > now || now - value.at >= 30 * DAY) return null;
    var landing = url(value.landing, true);
    if (!landing || !internal(landing, 'https://' + (CFG.host||'example.invalid') + '/')) return null;
    var params = new URL(landing).searchParams;
    if (!KEYS.some(function(k) { return params.has(k); })) return null;
    return {landing:landing, ref:url(value.ref), at:value.at};
  }
  // Keep one tagged landing page for this browser tab only. This covers the
  // common flow ad landing -> internal product/contact page -> form submit
  // when the optional consent API is not available. It expires after the
  // visit window, never uses a cookie/localStorage, and contains no PII.
  function sessionRecord(value, now) {
    if (!value || !Number.isFinite(value.at) || value.at > now || now - value.at >= IDLE) return null;
    var landing = url(value.landing, true);
    if (!landing || !internal(landing, 'https://' + (CFG.host||'example.invalid') + '/')) return null;
    var params = new URL(landing).searchParams;
    if (!KEYS.some(function(k) { return params.has(k); })) return null;
    return {landing:landing, ref:url(value.ref), at:value.at};
  }
  function selectMarketing(saved, current, first, now) {
    var visit = marketingRecord(current, now);
    // Use the most recent complete ad entry; first-touch history stays separate.
    // This browser history is only read/written after consent, with the existing TTL.
    var candidates=[marketingRecord(first,now),marketingRecord(saved,now),visit].filter(Boolean);
    candidates.sort(function(a,b){return b.at-a.at;});
    var selected=candidates[0]||null;
    return {record:selected, source:selected?{landing:selected.landing,ref:selected.ref,at:selected.at}:null, ad:selected ? ads(selected.landing, selected.ref) : ads('https://' + (CFG.host||'example.invalid') + '/', ''),
      basis:selected ? (visit && selected.at===visit.at && selected.landing===visit.landing?'current_visit':'retained_marketing') : 'none'};
  }
  function writePacket(form, values) {
    if (form.hasAttribute('data-xi-inquiry')) {
      try {
        var product = JSON.parse(form.getAttribute('data-xi-product') || 'null');
        if (product) {
          values._product = {id:product.id, signature:product.signature};
          [['Equipment',product.equipment],['Product',product.model],['ProductID',String(product.id)]].forEach(function(pair) {
            var field=form.querySelector('input[type="hidden"][name="'+pair[0]+'"]');
            if(field) field.value=pair[1] || '';
          });
        }
      } catch (_) {}
    }
    var input=form.querySelector('input[name="xi_context"]');
    if(!input) {
      input=form.ownerDocument.createElement('input'); input.type='hidden'; input.name='xi_context';
      input.setAttribute('data-nixm-owned','1'); form.appendChild(input);
    }
    input.value=JSON.stringify(values);
  }
  function advance(first, visit, href, ref, now, navigationType) {
    var fresh = entry(href, ref, now), sameSite = internal(ref, href);
    var reload = navigationType === 'reload' || navigationType === 'back_forward';
    if (!first || !Number.isFinite(first.at) || first.at > now || now - first.at >= 30 * DAY) first = {ref:fresh.ref, landing:fresh.landing, at:now};
    var tagged = KEYS.some(function (k) { return new URL(href).searchParams.has(k); });
    var newVisit = !visit || !visit.entry || !Number.isFinite(visit.lastAt) || now < visit.lastAt || now - visit.lastAt >= IDLE || (!reload && !sameSite && (fresh.ref || tagged));
    if (newVisit) visit = {entry:fresh, lastAt:now, page:url(href), previous:sameSite ? url(ref) : '', cta:null};
    else {
      visit = Object.assign({}, visit);
      var page = url(href);
      visit.previous = !reload && page !== visit.page ? (sameSite ? url(ref) : '') : (visit.previous || '');
      visit.page = page; visit.lastAt = now;
    }
    return { first:first, visit:visit };
  }
  function start(w) {
    if (!w || w.location.hostname !== CFG.host || /[?&](bricks|brickspreview)=/.test(w.location.search)) return;
    var d = w.document, initialHref = w.location.href, initialRef = d.referrer;
    // A tagged page carries its own codes. An untagged page may receive codes from the previous page of this tab;
    // a page URL that already has campaign codes always wins, and the marker is removed from the address bar.
    var own = codes(new URL(initialHref).searchParams), carried = own ? {codes:own, at:0} : null, landingHref = initialHref;
    if (!own) {
      var arrived = Date.now(), fromRef = referrerCodes(initialRef, initialHref);
      carried = carriedCodes(w.location.hash, arrived) || (fromRef ? {codes:fromRef, at:arrived} : null);
      if (carried) landingHref = withCodes(initialHref, carried.codes);
    }
    if (w.location.hash.indexOf(MARK) === 0) { try { w.history.replaceState(w.history.state, '', w.location.pathname + w.location.search); } catch (_) {} }
    function landing() { return carried && carried.at && Date.now() - carried.at >= IDLE ? initialHref : landingHref; }
    // Bricks AJAX pagination moves the address without a reload: the snapshot must name the page the form is really on.
    function onCurrentPath(href) {
      try { var u = new URL(href), path = new URL(w.location.href).pathname; if (u.pathname === path) return href; u.pathname = path; return u.href; } catch (_) { return href; }
    }
    var nav = w.performance && typeof w.performance.getEntriesByType==='function' && w.performance.getEntriesByType('navigation')[0];
    var navigationType = nav ? nav.type : 'navigate';
    var state = null, allowed = false, operational = null, pendingPopup = null, popupCTA = new WeakMap(), inlineCTA = new WeakMap();
    var consentStatus = 'consent_unavailable', storageFailed = false;
    function consent() {
      try {
        var c = w.consentApi.consentSync(SERVICE);
        consentStatus = !c || !c.cookie ? 'service_missing' : (!c.consentGiven ? 'consent_pending' : (!c.cookieOptIn ? 'consent_declined' : 'allowed'));
        return consentStatus === 'allowed';
      } catch (_) { consentStatus = 'consent_unavailable'; return false; }
    }
    function read(store, key) { try { var raw = w[store].getItem(key); return raw && raw.length < 16000 ? JSON.parse(raw) : null; } catch (_) { storageFailed = true; return null; } }
    function remove() { try { w.localStorage.removeItem(FIRST); w.localStorage.removeItem(MARKETING); } catch (_) {} try { w.sessionStorage.removeItem(VISIT); } catch (_) {} }
    function removeOperational() { try { w.sessionStorage.removeItem(SESSION); } catch (_) {} operational = null; }
    function refreshOperational() {
      if(!allowed){removeOperational();return null;}
      var now = Date.now(), saved = sessionRecord(read('sessionStorage', SESSION), now);
      var current = sessionRecord({landing:url(landing(),true),ref:url(initialRef),at:now}, now);
      // A newly tagged page is a new authoritative ad entry for this tab.
      operational = current || saved;
      try {
        if (operational) w.sessionStorage.setItem(SESSION, JSON.stringify(operational));
        else w.sessionStorage.removeItem(SESSION);
      } catch (_) { storageFailed = true; }
      return operational;
    }
    function marketing() {
      var now = Date.now(), stored = marketingRecord(read('localStorage', MARKETING), now);
      var memory = marketingRecord(state.marketing, now);
      if (memory && (!stored || memory.at < stored.at)) stored = memory;
      var selected = selectMarketing(stored, state.visit.entry, state.first, now);
      state.marketing = selected.record;
      return selected;
    }
    function save() {
      if (!allowed || !state) return;
      marketing();
      try {
        w.localStorage.setItem(FIRST, JSON.stringify(state.first));
        if (state.marketing) w.localStorage.setItem(MARKETING, JSON.stringify(state.marketing));
        else w.localStorage.removeItem(MARKETING);
      } catch (_) { storageFailed = true; }
      try { w.sessionStorage.setItem(VISIT, JSON.stringify(state.visit)); } catch (_) { storageFailed = true; }
    }
    function refresh() {
      var next = consent();
      if (!next) { if (allowed || consentStatus === 'consent_declined') remove(); if (consentStatus === 'consent_declined') removeOperational(); allowed = false; state = null; pendingPopup = null; popupCTA = new WeakMap(); inlineCTA = new WeakMap(); return; }
      if (!allowed || !state) {
        state = advance(read('localStorage', FIRST), read('sessionStorage', VISIT), landing(), initialRef, Date.now(), navigationType);
        // Drop text/position retained by an older page in this tab's visit record.
        if (state.visit.cta) { var oldCTA = state.visit.cta; state.visit.cta = {id:text(oldCTA.id),at:oldCTA.at,to:oldCTA.to}; }
        allowed = true; save();
      }
    }
    function touch() {
      refresh(); if (!state) return;
      var now = Date.now();
      if (now - state.visit.lastAt >= IDLE) {
        save(); // Preserve the collected marketing entry before resetting the visit.
        state.visit = {entry:entry(w.location.href, '', now), lastAt:now, page:url(w.location.href), previous:'', cta:null};
        // The old landing URL's marketing tags do not represent a fresh click after inactivity.
        state.visit.entry.ad = ads(url(w.location.href), '');
        state.visit.entry.landing = url(w.location.href);
        pendingPopup = null; popupCTA = new WeakMap(); inlineCTA = new WeakMap();
      }
      state.visit.lastAt = now; save();
    }
    function set(form, key, value) {
      form.querySelectorAll('[name="form-field-' + key + '"]').forEach(function (el) { var val = text(value, 1800); if (el.value !== val) { el.value = val; el.dispatchEvent(new Event('input', {bubbles:true})); el.dispatchEvent(new Event('change', {bubbles:true})); } });
    }
    function button(el) {
      return {id:text(el.id || el.getAttribute('data-inquiry-id') || (el.closest('[id]') || {}).id), at:Date.now()};
    }
    function fill(form, submitter) {
      set(form, 'H1', url(w.location.href));
      var values = {A1:'',A2:'',A3:'',A4:'',A5:'',A6:'',A7:'',R1:'',R2:'',R3:'',R4:'',R5:'',R6:''};
      var diagnostic = {version:'1.0.6', status:consentStatus, basis:'none', storage:'not_used'}, source = null;
      // An in-memory snapshot of this page, not a cross-page tracking store.
      // Rejection clears retained history, not the URL already opened on this page.
      var here = landing();
      var request = {landing_ad_url:url(onCurrentPath(here),true),referrer:url(initialRef),
        consent_status:allowed?'accepted':(consentStatus==='consent_declined'?'rejected':'unknown')};
      if (here !== initialHref) diagnostic.carried = 'previous_page_codes';
      Object.assign(values,ads(here,initialRef));
      if (allowed && state) {
        var selected = marketing();
        source = selected.source || {landing:state.visit.entry.landing,ref:state.visit.entry.ref,at:state.visit.entry.at};
        Object.assign(values, selected.ad, {R1:state.first.ref,R2:state.first.landing,R3:state.visit.entry.ref,R4:state.visit.entry.landing,R5:state.visit.previous});
        diagnostic.basis = selected.basis;
        diagnostic.status = values.A1 ? 'campaign_captured' : (values.A5 ? 'click_without_campaign' : 'no_campaign_parameter');
        diagnostic.storage = storageFailed ? 'unavailable' : 'available';
        var popup = form.closest('.brx-popup'), cta = popup ? popupCTA.get(popup) : inlineCTA.get(form);
        var linked = state.visit.cta;
        if (!popup && !cta && linked && linked.to === url(w.location.href) && Date.now() - linked.at < IDLE) cta = linked;
        if (!cta && submitter) cta = button(submitter);
        if (cta && Date.now() - cta.at < IDLE) values.R6 = cta.id;
      }
      var session = allowed ? sessionRecord(operational || read('sessionStorage', SESSION), Date.now()) : null;
      if (!source && session) {
        var sessionAd = ads(session.landing, session.ref);
        Object.keys(sessionAd).forEach(function(k) { if (!values[k]) values[k] = sessionAd[k]; });
        Object.assign(values, {R1:session.ref,R2:session.landing,R3:session.ref,R4:session.landing});
        source = {landing:session.landing,ref:session.ref,at:session.at};
        diagnostic.basis = 'session_landing';
        diagnostic.storage = storageFailed ? 'unavailable' : 'session_only';
      }
      diagnostic.status = values.A1 ? 'campaign_captured' : (values.A5 ? 'click_without_campaign' : 'no_campaign_parameter');
      Object.keys(values).forEach(function(k) { set(form,k,values[k]); });
      var measurement=w.XIConversions&&typeof w.XIConversions.consents==='function'?w.XIConversions.consents():{};
      writePacket(form,Object.assign({H1:url(w.location.href), _tracking:diagnostic, _source:source, _request:request, _measurement:measurement},values));
    }
    function fillAll() { d.querySelectorAll(FORM).forEach(function(f) { fill(f); }); }
    function click(e) {
      touch();
      var el = e.target.closest && e.target.closest('[data-interactions],a,button,[data-inquiry-id]');
      if (!el || !allowed) return;
      var interactions = []; try { interactions = JSON.parse(el.getAttribute('data-interactions') || '[]'); } catch (_) {}
      var open = interactions.find(function(i) { return i.trigger === 'click' && i.action === 'show' && i.target === 'popup'; });
      if (open) { pendingPopup = {popup:String(open.templateId), data:button(el)}; }
      if (interactions.some(function(i) { return i.trigger === 'click' && i.action === 'hide'; })) {
        var closing = el.closest('.brx-popup'); if (closing) popupCTA.delete(closing);
        pendingPopup = null;
      }
      var href = el.getAttribute('href');
      if (href) {
        try {
          var target = new URL(href,w.location.href);
          if (internal(target.href,w.location.href)) {
            if (target.hash && url(target.href) === url(w.location.href)) {
              var dest = d.getElementById(decodeURIComponent(target.hash.slice(1)));
              if (dest) dest.querySelectorAll(FORM).forEach(function(f) {inlineCTA.set(f,button(el));});
              if (dest && dest.matches(FORM)) inlineCTA.set(dest,button(el));
            } else if (/\/(contact-us|contacto)\/?$/.test(target.pathname)) {
              state.visit.cta = Object.assign(button(el),{to:url(target.href)}); save();
            }
          }
        } catch (_) {}
      }
      fillAll();
    }
    d.addEventListener('click',click,true);
    // Only while there is no consent: retained history covers the consented visitor.
    function decorate(e) {
      if (allowed || !carried) return;
      var a = e.target && e.target.closest && e.target.closest('a[href]');
      // AJAX pagination stays on this document and would push the marker into the address bar.
      if (!a || a.hasAttribute('download') || a.closest('.brx-ajax-pagination')) return;
      try {
        var now = Date.now(), target = new URL(a.getAttribute('href'), w.location.href), here = new URL(w.location.href);
        if (carried.at && now - carried.at >= IDLE) return;
        if (!/^https?:$/.test(target.protocol) || !internal(target.href, here.href)) return;
        if (target.hash && target.hash.indexOf(MARK) !== 0) return;
        if (target.origin + target.pathname + target.search === here.origin + here.pathname + here.search) return;
        if (/^\/(wp-admin|wp-login\.php|wp-json)(\/|$)/.test(target.pathname) || /\.(pdf|zip|rar|jpe?g|png|gif|webp|svg|docx?|xlsx?|pptx?|mp4)$/i.test(target.pathname)) return;
        target.hash = carryHash(carried.codes, now);
        a.setAttribute('href', target.href);
      } catch (_) {}
    }
    ['mousedown','touchstart','click','auxclick','contextmenu','keydown'].forEach(function(name) { d.addEventListener(name,decorate,{capture:true,passive:true}); });
    var lastActivityWrite = 0;
    function activity() { if (Date.now() - lastActivityWrite > 10000) { lastActivityWrite = Date.now(); touch(); } }
    d.addEventListener('keydown',activity,true);
    d.addEventListener('scroll',activity,{passive:true});
    d.addEventListener('focusin',function(e) { if (e.target.closest && e.target.closest(FORM)) { touch(); fillAll(); } });
    d.addEventListener('bricks/popup/open',function(e) {
      var p = e.detail && e.detail.popupElement;
      if (p && pendingPopup && String(e.detail.popupId) === pendingPopup.popup && Date.now() - pendingPopup.data.at < 2000) popupCTA.set(p,pendingPopup.data);
      pendingPopup = null; fillAll();
    });
    d.addEventListener('bricks/popup/close',function(e) { if (e.detail && e.detail.popupElement) popupCTA.delete(e.detail.popupElement); pendingPopup = null; fillAll(); });
    d.addEventListener('submit',function(e) {
      if (!e.target.matches(FORM)) return;
      touch(); fill(e.target,e.submitter || e.target.querySelector('button[type="submit"]'));
      // Leave field values through serialization; clear only the stored association.
      if (state) { state.visit.cta = null; save(); }
    },true);
    d.addEventListener('reset',function(e) { if (e.target.matches(FORM)) { inlineCTA.delete(e.target); var p=e.target.closest('.brx-popup'); if(p) popupCTA.delete(p); } });
    ['RCB/Apply/Interactive','RCB/OptIn','RCB/OptOut'].forEach(function(name) {
      d.addEventListener(name,function() { w.setTimeout(function() { refresh(); fillAll(); },0); });
    });
    w.addEventListener('pageshow',function(e) {
      if (!e.persisted) return;
      pendingPopup=null; popupCTA=new WeakMap(); inlineCTA=new WeakMap(); refresh();
      if (allowed) {
        var latest=read('sessionStorage',VISIT);
        state=advance(read('localStorage',FIRST),latest,w.location.href,latest && latest.page || '',Date.now(),'navigate');
        state.visit.cta=null; save();
      }
      refreshOperational(); fillAll();
    });
    function ready() { refresh(); refreshOperational(); fillAll(); }
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded',ready); else ready();
    w.addEventListener('load',ready);
  }
  return {url:url,ads:ads,advance:advance,selectMarketing:selectMarketing,sessionRecord:sessionRecord,writePacket:writePacket,start:start};
});
