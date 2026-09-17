/* Apply a saved light/dark choice before the page paints, so there's no flash of the wrong theme. */
try {
  var saved = localStorage.getItem('finpulse-theme');
  if (saved === 'light' || saved === 'dark') document.documentElement.dataset.theme = saved;
} catch (e) { /* storage unavailable: follow the system theme */ }
