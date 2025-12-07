// theme.js — simple light/dark toggle with localStorage
(function () {
  const KEY = 'cc_theme';
  const root = document.documentElement;
  function applyTheme(name) {
    if (name === 'dark') root.classList.add('dark'); else root.classList.remove('dark');
  }
  // initialize from localStorage or prefers-color-scheme
  let theme = localStorage.getItem(KEY);
  if (!theme) {
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    theme = prefersDark ? 'dark' : 'light';
  }
  applyTheme(theme);

  // expose toggle for buttons
  window.CCTheme = {
    toggle: function () {
      const cur = root.classList.contains('dark') ? 'dark' : 'light';
      const next = cur === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      localStorage.setItem(KEY, next);
      // update button label if present
      const btn = document.getElementById('theme-toggle');
      if (btn) btn.textContent = next === 'dark' ? '🌙' : '☀️';
    }
  };

  // on DOM ready, set toggle button icon
  document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('theme-toggle');
    if (btn) {
      const isDark = root.classList.contains('dark');
      btn.textContent = isDark ? '🌙' : '☀️';
      btn.addEventListener('click', function (e) { e.preventDefault(); window.CCTheme.toggle(); });
    }
  });
})();
document.querySelector('label[for="nav-toggle"]').addEventListener('click', (e) => {
  e.stopPropagation();
});
