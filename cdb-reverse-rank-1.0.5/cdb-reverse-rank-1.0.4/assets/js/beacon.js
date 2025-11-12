(function(){
  try{
    if (!document.referrer) return;
    if (typeof CDBRR === 'undefined') return;

    var home = CDBRR.home || '';
    if (document.referrer.indexOf(home) === 0) return;

    var fd = new FormData();
    fd.append('action','cdb_ref_beacon');
    fd.append('nonce', CDBRR.nonce || '');
    fd.append('ref_url', document.referrer);
    fd.append('ref_title', document.title || '');

    if (navigator.sendBeacon) {
      navigator.sendBeacon(CDBRR.ajax, fd);
    } else {
      fetch(CDBRR.ajax, {method:'POST', body: fd, credentials:'same-origin'});
    }
  }catch(e){}
})();