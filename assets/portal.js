/* RightWin QR Portal – light behavior helpers */
/* global rwqrPortal, jQuery */
(function($){
  function byId(id){ return document.getElementById(id); }
  function show(el){ if(el) el.style.display=''; }
  function hide(el){ if(el) el.style.display='none'; }

  // Wizard: toggle fieldsets by content type
  function updateContentType(){
    var sel = byId('qr_content_type');
    if(!sel) return;
    var ct = sel.value;

    // hide all
    var sets = document.querySelectorAll('.rwqr-fieldset');
    sets.forEach(function(s){ hide(s); });

    // map ct -> class
    var map = {
      link: '.rwqr-ct-link',
      text: '.rwqr-ct-text',
      vcard: '.rwqr-ct-vcard',
      file: '.rwqr-ct-file',
      catalogue: '.rwqr-ct-catalogue',
      price: '.rwqr-ct-price',
      social: '.rwqr-ct-social',
      greview: '.rwqr-ct-greview',
      form: '.rwqr-ct-form'
    };
    var selCls = map[ct];
    if(selCls){
      document.querySelectorAll(selCls).forEach(function(s){ show(s); });
    }
  }

  // Wizard: show/hide dynamic-only fields
  function updateMode(){
    var mode = byId('qr_mode');
    if(!mode) return;
    var isDynamic = mode.value === 'dynamic';
    document.querySelectorAll('.rwqr-dynamic-only').forEach(function(s){
      if(isDynamic) show(s); else hide(s);
    });
  }

  // File size validation (logo)
  function logoGuard(){
    var input = document.querySelector('input[name="qr_logo"]');
    if(!input) return;
    input.addEventListener('change', function(){
      try{
        if(!this.files || !this.files[0]) return;
        var maxMB = (rwqrPortal && rwqrPortal.maxLogoMB) ? parseFloat(rwqrPortal.maxLogoMB) : 2;
        var sizeMB = this.files[0].size / (1024*1024);
        if(sizeMB > maxMB){
          alert('Logo exceeds maximum size of '+maxMB+' MB. Please choose a smaller file.');
          this.value = '';
        }
      }catch(e){}
    });
  }

  // Enhance Quick Edit toggles if present (progressive; PHP already inlines)
  function quickEditEnhance(){
    // No-op: handled by inline onclick, but keep hook if we want to expand later
  }

  // Init on DOM ready
  $(function(){
    // Wizard hooks
    if(byId('qr_content_type')){
      updateContentType();
      byId('qr_content_type').addEventListener('change', updateContentType);
    }
    if(byId('qr_mode')){
      updateMode();
      byId('qr_mode').addEventListener('change', updateMode);
    }
    logoGuard();
    quickEditEnhance();

    // If Elementor preview is active, keep things visible and non-interactive
    if (document.body.classList.contains('elementor-editor-active')) {
      // Ensure some fieldsets are visible for design-time
      document.querySelectorAll('.rwqr-fieldset').forEach(function(s){ s.style.display=''; });
    }
  });

})(jQuery);
// --- RWQR: Harden mailto buttons ---
(function(){
  function onMailtoClick(e){
    try{
      var a = e.currentTarget;
      var link = (a.getAttribute('data-mailto') || a.getAttribute('href') || '').trim();
      if (!link) return;
      // Some themes stopPropagation on .btn; force navigation
      window.location.href = link;
      // prevent any parent form or handler from canceling
      e.preventDefault();
      e.stopPropagation();
    } catch(err){}
  }
  document.addEventListener('click', function(e){
    var el = e.target;
    if (!el) return;
    // climb up to the anchor if an inner span is clicked
    if (el.closest) {
      var a = el.closest('a.rwqr-mailto');
      if (a) {
        onMailtoClick(Object.assign(e, { currentTarget: a }));
      }
    }
  }, true); // capture phase to beat other listeners
})();
// --- RWQR: Bullet-proof Email open helper ---
window.rwqrOpenMail = function(el){
  try {
    var link = (el && el.getAttribute('data-mailto')) || '';
    if (!link) return false;

    // Use window.open with _self to satisfy some mobile & desktop clients
    // (Safari/iOS & some Android mail apps prefer a direct navigation)
    window.open(link, '_self');

    // As a final fallback (very defensive)
    setTimeout(function(){
      try { window.location.href = link; } catch(e){}
    }, 50);

    return false; // prevent any parent default/propagation
  } catch (e) {
    // If anything unexpectedly fails, still hard-navigate
    try { window.location.href = (el && el.getAttribute('data-mailto')) || ''; } catch(e2){}
    return false;
  }
};

// Also bind click in capture phase in case inline handlers are stripped
(function(){
  document.addEventListener('click', function(ev){
    var target = ev.target && ev.target.closest && ev.target.closest('button.rwqr-mailto, a.rwqr-mailto');
    if (!target) return;
    // If the inline handler was removed by a sanitizer, call our global
    if (typeof window.rwqrOpenMail === 'function') {
      ev.preventDefault();
      ev.stopPropagation();
      window.rwqrOpenMail(target);
    }
  }, true);
})();
// RWQR: Inject Terms + Privacy checkboxes into the Register form (no PHP changes)
(function(){
  function addConsentBoxes(){
    var form = document.querySelector('.rwqr-register-form');
    if(!form || form.dataset.rwqrConsentInjected === '1') return;

    // find submit button row to insert before it
    var btn = form.querySelector('button, input[type="submit"]');
    var holder = document.createElement('div');
    holder.style.margin = '10px 0';
    holder.style.padding = '10px';
    holder.style.border = '1px solid #e5e7eb';
    holder.style.borderRadius = '10px';
    holder.style.background = '#fafafa';
    holder.innerHTML =
      '<label style="display:block;margin:6px 0">' +
        '<input type="checkbox" name="accept_terms" value="1" required> ' +
        'I accept the <a href="/terms" target="_blank" rel="noopener">Terms &amp; Conditions</a>.' +
      '</label>' +
      '<label style="display:block;margin:6px 0">' +
        '<input type="checkbox" name="accept_privacy" value="1" required> ' +
        'I have read the <a href="/privacy-policy" target="_blank" rel="noopener">Privacy Policy</a>.' +
      '</label>';

    if (btn && btn.parentNode){
      btn.parentNode.parentNode.insertBefore(holder, btn.parentNode);
    } else {
      form.appendChild(holder);
    }

    // enforce on submit (even if theme ignores "required")
    form.addEventListener('submit', function(e){
      var t = form.querySelector('input[name="accept_terms"]');
      var p = form.querySelector('input[name="accept_privacy"]');
      if(!(t && t.checked && p && p.checked)){
        e.preventDefault();
        alert('Please accept the Terms & Privacy to continue.');
        return false;
      }
    }, true);

    form.dataset.rwqrConsentInjected = '1';
  }

  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', addConsentBoxes);
  } else {
    addConsentBoxes();
  }
})();
