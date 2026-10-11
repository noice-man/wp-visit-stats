(function () {
  'use strict';
  var cfg = window.WPVS_CONFIG;
  if (!cfg || typeof window.fetch !== 'function') return;
  var key = 'wpvstats_consent';
  var sent = false;
  var cancelled = false;
  function hasConsent() {
    try { return window.localStorage.getItem(key) === 'granted'; } catch (e) { return false; }
  }
  function dnt() {
    return cfg.respectDnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1');
  }
  function send() {
    if (sent || cancelled || dnt() || (cfg.requireConsent && !hasConsent())) return;
    sent = true;
    var p = new URLSearchParams();
    p.set('action', 'wpvs_track');
    p.set('path', window.location.pathname);
    p.set('referrer', document.referrer || '');
    p.set('post_id', String(cfg.postId || 0));
    p.set('consent', hasConsent() ? 'yes' : 'no');
    var params = new URLSearchParams(window.location.search);
    ['utm_source', 'utm_medium', 'utm_campaign'].forEach(function (k) { p.set(k, params.get(k) || ''); });
    window.fetch(cfg.endpoint, {method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:p.toString()}).catch(function () {});
  }
  window.WPVisitStats = {
    grantConsent: function () {
      cancelled = false;
      try { window.localStorage.setItem(key, 'granted'); } catch (e) {}
      send();
    },
    denyConsent: function () {
      cancelled = true;
      try { window.localStorage.setItem(key, 'denied'); } catch (e) {}
      var p = new URLSearchParams(); p.set('action','wpvs_optout');
      window.fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:p.toString()}).catch(function () {});
    }
  };
  // defer to page load so cached HTML and ordinary navigation still count.
  if (document.readyState === 'complete') send();
  else window.addEventListener('load',send,{once:true});
}());
