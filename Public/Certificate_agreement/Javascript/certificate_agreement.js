// certificate_agreement.js - theme toggle + blurred header on scroll
(function () {
  const header = document.getElementById('sqHeader');
  const themeBtn = document.getElementById('sqThemeBtn');

  const applyTheme = (theme) => {
    document.documentElement.setAttribute('data-theme', theme);
    if (themeBtn) {
      themeBtn.innerHTML = theme === 'dark' ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
      themeBtn.title = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
      themeBtn.setAttribute('aria-label', themeBtn.title);
    }
  };

  const saved = localStorage.getItem('sq-theme') || 'light';
  applyTheme(saved);

  themeBtn?.addEventListener('click', () => {
    const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    localStorage.setItem('sq-theme', next);
    applyTheme(next);
  });

  const onScroll = () => {
    if (!header) return;
    header.classList.toggle('sq-header--scrolled', window.scrollY > 4);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();

