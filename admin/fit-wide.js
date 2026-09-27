/* Scales the fixed-width console to the viewport. (c) 2026 Ellipsis. */
(function () {
  var start = Date.now();

  function root() {
    var b = document.body.children;
    for (var i = 0; i < b.length; i++) if (b[i].scrollWidth > 400) return b[i];
    return null;
  }

  function widest(r) {
    var w = r.scrollWidth, all = r.querySelectorAll('*');
    for (var i = 0; i < all.length; i++) {
      if (all[i].offsetParent === null) continue;
      if (all[i].offsetWidth > w) w = all[i].offsetWidth;
    }
    return w;
  }

  function fit() {
    var r = root();
    if (!r) return false;
    r.style.transform = ''; r.style.width = '';
    var w = widest(r);
    if (w < 400) return false;
    var s = Math.min(1, (window.innerWidth - 16) / w);
    r.style.transformOrigin = 'top left';
    if (s < 0.999) {
      r.style.transform = 'scale(' + s.toFixed(4) + ')';
      r.style.width = (100 / s) + '%';
    }
    document.body.style.overflowX = 'hidden';
    return true;
  }

  (function poll() { if (!fit() && Date.now() - start < 15000) requestAnimationFrame(poll); })();
  addEventListener('resize', fit);
  addEventListener('load', function () { setTimeout(fit, 400); });
})();
