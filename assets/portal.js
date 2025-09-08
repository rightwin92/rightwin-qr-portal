// Email button handler
window.rwqrOpenMail = function(btn){
  var url = btn.getAttribute('data-mailto');
  if(url){
    window.open(url,'_blank','noopener');
  }
  return false;
};

// Enforce consent even if theme disables 'required'
(function(){
  var form = document.querySelector('.rwqr-register-form');
  if(!form) return;
  form.addEventListener('submit', function(e){
    var t = form.querySelector('input[name="accept_terms"]');
    var p = form.querySelector('input[name="accept_privacy"]');
    if(!(t && t.checked && p && p.checked)){
      e.preventDefault();
      alert('Please accept the Terms & Conditions and the Privacy Policy to continue.');
      return false;
    }
  }, true);
})();
