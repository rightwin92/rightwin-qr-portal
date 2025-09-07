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
