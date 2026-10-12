(function () {
  'use strict';
  var cfg = window.WPVS_CONFIG;
  if (!cfg || typeof window.fetch !== 'function') return;
  var key = 'wpvstats_consent';
  var sent = false;
  var cancelled = false;
  var recentGoals = Object.create(null);
  function hasConsent() {
    try { return window.localStorage.getItem(key) === 'granted'; } catch (e) { return false; }
  }
  function dnt() { return cfg.respectDnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1'); }
  function allowed() { return !cancelled && !dnt() && (!cfg.requireConsent || hasConsent()); }
  function request(p) {
    return window.fetch(cfg.endpoint, {method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:p.toString()}).catch(function () {});
  }
  function base(action) {
    var p = new URLSearchParams();
    p.set('action', action);
    p.set('path', window.location.pathname);
    p.set('post_id', String(cfg.postId || 0));
    p.set('consent', hasConsent() ? 'yes' : 'no');
    return p;
  }
  function send() {
    if (sent || !allowed()) return;
    sent = true;
    var p = base('wpvs_track');
    p.set('referrer', document.referrer || '');
    var params = new URLSearchParams(window.location.search);
    ['utm_source', 'utm_medium', 'utm_campaign'].forEach(function (k) { p.set(k, params.get(k) || ''); });
    return request(p);
  }
  function validGoal(goal) { return typeof goal === 'string' && /^[a-zA-Z0-9_.:-]{1,80}$/.test(goal); }
  function goal(name, origin, counter) {
    if (!cfg.goalTracking || !allowed() || !validGoal(name)) return false;
    // Avoid two counters or a manual event + reachGoal counting the same action twice.
    // Same goal may legitimately recur after this short window.
    var now = Date.now();
    if (recentGoals[name] && now - recentGoals[name] < 750) return false;
    recentGoals[name] = now;
    var p = base('wpvs_goal');
    p.set('goal', name);
    p.set('source', origin === 'metrika' ? 'metrika' : 'manual');
    p.set('counter_id', /^[0-9]{1,12}$/.test(String(counter || 0)) ? String(counter || 0) : '0');
    // Deliberately NEVER forward reachGoal's params: they may contain order/user data.
    request(p);
    return true;
  }
  window.WPVisitStats = {
    goal: function (name) { return goal(name, 'manual', 0); },
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
  if (cfg.goalTracking && cfg.mirrorMetrika) {
    // Best effort only: wraps the public ym() JS API, never network traffic or internal API.
    // A third-party script can replace the wrapper; a bounded watch reattaches it.
    function wrapYM() {
      var original = window.ym;
      if (typeof original !== 'function' || original.__wpvsMirror === true) return;
      var wrapped = function () {
        if (arguments.length >= 3 && arguments[1] === 'reachGoal') {
          try { goal(arguments[2], 'metrika', arguments[0]); } catch (e) {}
        }
        return original.apply(this, arguments);
      };
      Object.keys(original).forEach(function (property) {
        if (property !== '__wpvsMirror') {
          try { wrapped[property] = original[property]; } catch (e) {}
        }
      });
      wrapped.__wpvsMirror = true;
      window.ym = wrapped;
    }
    wrapYM();
    var watches = 0;
    var watch = window.setInterval(function () {
      wrapYM();
      if (++watches >= 60) window.clearInterval(watch);
    }, 500);
  }
  // defer pageview to load so cached HTML and ordinary navigation still count.
  if (document.readyState === 'complete') send();
  else window.addEventListener('load',send,{once:true});
}());
