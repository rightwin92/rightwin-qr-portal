/* RightWin QR Portal JS */
(function(){
  function $(sel, ctx){ return (ctx||document).querySelector(sel); }
  function $all(sel, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(sel)); }
  function byId(id){ return document.getElementById(id); }

  function toggleContentFields(){
    var typeSel = byId('qr_content_type');
    if(!typeSel) return;
    var val = (typeSel.value || 'link').toLowerCase();

    $all('.rwqr-fieldset').forEach(function(el){ el.style.display='none'; });

    var showCls = '.rwqr-ct-' + val;
    var el = $(showCls);
    if(el) el.style.display = '';

    var modeSel = byId('qr_mode') || $('[name="qr_type"]');
    var dynOnly = $('.rwqr-dynamic-only');
    if (dynOnly && modeSel){
      var isDynamic = (modeSel.value || 'dynamic') === 'dynamic';
      dynOnly.style.display = isDynamic ? '' : 'none';
    }
  }

  function onModeChange(){ toggleContentFields(); }

  function bind(){
    var typeSel = byId('qr_content_type');
    if(typeSel){
      typeSel.addEventListener('change', toggleContentFields);
      toggleContentFields();
    }
    var modeSel = byId('qr_mode') || $('[name="qr_type"]');
    if(modeSel){
      modeSel.addEventListener('change', onModeChange);
      if(!modeSel.id) modeSel.id = 'qr_mode';
      onModeChange();
    }

    var logo = $('[name="qr_logo"]');
    if (logo && typeof rwqrPortal !== 'undefined'){
      var maxMB = parseFloat(rwqrPortal.maxLogoMB || 2);
      logo.addEventListener('change', function(){
        if(!logo.files || !logo.files[0]) return;
        var sizeMB = logo.files[0].size / (1024*1024);
        if(sizeMB > maxMB){
          alert('Logo is too large. Max allowed: ' + maxMB + ' MB');
          logo.value = '';
        }
      });
    }

    // Auto-prepend https:// if missing
    $all('input[type="text"], input[type="url"]').forEach(function(inp){
      inp.addEventListener('blur', function(){
        var v = (inp.value || '').trim();
        if(!v) return;
        var hasScheme = /^[a-z][a-z0-9+\-.]*:\/\//i.test(v) || v.indexOf('//')===0;
        var looksDomain = /^[a-z0-9.-]+\.[a-z]{2,}($|\/|#|\?)/i.test(v);
        if(!hasScheme && looksDomain){
          inp.value = 'https://' + v;
        }
      });
    });
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
