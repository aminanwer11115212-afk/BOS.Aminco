(function(){
  function getTheme(){
    return document.documentElement.getAttribute('data-bs-theme') || 'light';
  }

  function setTheme(theme){
    document.documentElement.setAttribute('data-bs-theme', theme);
    try { localStorage.setItem('theme', theme); } catch(e) {}
    updateToggleIcon();
  }

  function updateToggleIcon(){
    var btn = document.getElementById('themeToggle');
    if(!btn) return;
    var icon = btn.querySelector('i');
    if(!icon) return;
    var theme = getTheme();
    // moon in light, sun in dark
    if(theme === 'dark'){
      icon.className = 'bi bi-sun';
      btn.setAttribute('aria-label','الوضع الفاتح');
      btn.title = 'الوضع الفاتح';
    } else {
      icon.className = 'bi bi-moon-stars';
      btn.setAttribute('aria-label','الوضع المظلم');
      btn.title = 'الوضع المظلم';
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    updateToggleIcon();

    var btn = document.getElementById('themeToggle');
    if(btn){
      btn.addEventListener('click', function(){
        var current = getTheme();
        setTheme(current === 'dark' ? 'light' : 'dark');
      });
    }

    // Auto submit forms for inputs marked with data-autosubmit
    document.querySelectorAll('form[data-autosubmit] input, form[data-autosubmit] select').forEach(function(el){
      el.addEventListener('change', function(){
        var form = el.closest('form');
        if(form) form.submit();
      });
    });
  });
})();
