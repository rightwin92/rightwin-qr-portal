(function($){
  $(document).on('change','input[name="qr_logo"]',function(){
    const f=this.files&&this.files[0]; if(!f) return;
    const maxMB = parseFloat(window.rwqrPortal?.maxLogoMB||2);
    if(f.size > maxMB*1024*1024){
      alert('Logo exceeds maximum size of '+maxMB+' MB');
      this.value='';
    }
  });
})(jQuery);

(function($){
  function toggleCt(){
    var ct = $('#qr_content_type').val();
    $('.rwqr-fieldset').hide();
    $('.rwqr-ct-'+ct).show();
    var mode = $('select[name="qr_type"]').val();
    if(mode === 'dynamic'){ $('.rwqr-dynamic-only').show(); } else { $('.rwqr-dynamic-only').hide(); }
  }
  $(document).on('change','#qr_content_type, select[name="qr_type"]', toggleCt);
  $(document).ready(toggleCt);
})(jQuery);
