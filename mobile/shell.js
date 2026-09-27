/* Ellipsis mobile shell — viewport fit, PWA registration. (c) 2026 Ellipsis. */
(function () {
  var W = 402, H = 874, start = Date.now();

  function fit() {
    var d = document.querySelector('[data-om-starter="ios-frame"]');
    if (!d) return false;
    var vw = window.innerWidth, vh = window.innerHeight;
    var wide = vw >= 900 && vh >= 720;
    var pad = wide ? 72 : 0;
    var s = Math.min((vw - pad) / W, (vh - pad) / H);
    if (wide) s = Math.min(s, 1);
    d.style.transformOrigin = 'center center';
    d.style.transform = 'scale(' + s.toFixed(4) + ')';
    if (!wide) {
      d.style.borderRadius = '0';
      d.style.boxShadow = 'none';
      for (var n = d.parentElement; n && n !== document.body; n = n.parentElement) {
        n.style.padding = '0';
        n.style.minHeight = '100vh';
      }
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';
      document.body.style.background = '#000';
    } else {
      document.documentElement.style.overflow = '';
      document.body.style.overflow = '';
    }
    return true;
  }

  (function poll() { if (!fit() && Date.now() - start < 15000) requestAnimationFrame(poll); })();
  addEventListener('resize', fit);
  addEventListener('orientationchange', function () { setTimeout(fit, 250); });
  document.addEventListener('gesturestart', function (e) { e.preventDefault(); });

  if ('serviceWorker' in navigator) {
    addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
  }
})();
