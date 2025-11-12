
(function(){
  function isVisible(el){
    if(!el) return false;
    var style = window.getComputedStyle(el);
    if(style.display==='none' || style.visibility==='hidden' || parseFloat(style.opacity)===0) return false;
    var rect = el.getBoundingClientRect();
    if((rect.width<=1 || rect.height<=1) && !(el.offsetWidth>1 && el.offsetHeight>1)) return false;
    return true;
  }

  function mark(state){
    window.CDB_CREDIT_OK = !!state;
    document.querySelectorAll('.cdb-rr-requires-credit').forEach(function(box){
      if(!window.CDB_CREDIT_OK){
        var card = box.querySelector('.cdb-ref-card');
        if(card){
          card.innerHTML = '<div class="cdb-rr-locked">このプラグインは「<a href="'+(window.CDBRR_CREDIT?window.CDBRR_CREDIT.credit_url:'https://companydata.tsujigawa.com/')+'" target="_blank" rel="noopener">全国企業データベース</a>」のクレジットを前面に可視状態で表示しているサイトでのみ動作します。クレジットが非表示の場合、ランキングは表示されません。</div>';
        }
      }
    });
  }

  function ensureBadge(){
    var badge = document.getElementById('cdb-rr-credit');
    if(!badge){
      badge = document.createElement('div');
      badge.id = 'cdb-rr-credit';
      badge.className = 'cdb-rr-credit';
      badge.setAttribute('data-required','1');
      badge.innerHTML = 'Powered by <a href="'+(window.CDBRR_CREDIT?window.CDBRR_CREDIT.credit_url:'https://companydata.tsujigawa.com/')+'" target="_blank" rel="noopener sponsored">全国企業データベース</a>';
      document.body.appendChild(badge);
    }
    return badge;
  }

  function check(){
    var badge = ensureBadge();
    var ok = isVisible(badge);
    mark(ok);
  }

  document.addEventListener('DOMContentLoaded', check);
  window.addEventListener('load', check);
  setInterval(check, 3000);
})();
