/* Apply a saved light/dark choice before the page paints, so there's no flash of the wrong theme. */
try {
  var saved = localStorage.getItem('sixpence-theme');
  if (saved === 'light' || saved === 'dark') document.documentElement.dataset.theme = saved;
} catch (e) { /* storage unavailable: follow the system theme */ }

/* Marks that scripting is on, so the stylesheet may hide scroll-reveal content
   knowing app.js will reveal it. Set here, before the first paint, so the page
   never flashes its content and then hides it. */
document.documentElement.dataset.js = '';
