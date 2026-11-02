/**
 * Apple iOS / HIG UI Engine for Cotizador ERP
 */
(function() {
  // Inicialización de Tema Claro/Oscuro
  const savedTheme = localStorage.getItem('cotizador-theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.setAttribute('data-theme', savedTheme);

  // Inicialización de Sidebar colapsada en Desktop
  if (window.innerWidth >= 992) {
    const collapsed = localStorage.getItem('cotizador-sidebar-collapsed') === 'true';
    if (collapsed) document.body.classList.add('sidebar-collapsed');
  }
})();

document.addEventListener('DOMContentLoaded', () => {
  const body = document.body;
  const sidebar = document.getElementById('ios-sidebar');
  const overlay = document.getElementById('ios-overlay');

  // Toggle Tema Claro / Oscuro
  document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('cotizador-theme', next);
    });
  });

  // Toggle Sidebar
  document.querySelectorAll('[data-sidebar-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (window.innerWidth >= 992) {
        // En desktop: colapsa o expande
        body.classList.toggle('sidebar-collapsed');
        const isCollapsed = body.classList.contains('sidebar-collapsed');
        localStorage.setItem('cotizador-sidebar-collapsed', isCollapsed ? 'true' : 'false');
      } else {
        // En móvil/tablet: abre el cajón lateral
        if (sidebar && overlay) {
          sidebar.classList.toggle('open');
          overlay.classList.toggle('active');
        }
      }
    });
  });

  // Cerrar sidebar al hacer clic en el overlay o en un enlace
  if (overlay) {
    overlay.addEventListener('click', () => {
      if (sidebar) sidebar.classList.remove('open');
      overlay.classList.remove('active');
    });
  }

  document.querySelectorAll('.ios-sidebar a').forEach(link => {
    link.addEventListener('click', () => {
      if (window.innerWidth < 992 && sidebar && overlay) {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
      }
    });
  });

  // Atajo de teclado Cmd+K / Ctrl+K
  window.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      const searchBox = document.querySelector('.ios-quick-search');
      if (searchBox) {
        window.location.href = searchBox.getAttribute('href');
      }
    }
  });

  // Auto-submit para selectores de fecha/mes tipo iOS
  document.querySelectorAll('.ios-month-picker input, [data-auto-submit]').forEach(input => {
    input.addEventListener('change', () => {
      if (input.form) input.form.submit();
    });
  });
});
