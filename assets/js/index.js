// RCSS Grievance Redressal Portal - Home (index.js)

document.addEventListener('DOMContentLoaded', () => {
  // Initialize Lucide icons
  if (typeof lucide !== 'undefined') {
    lucide.createIcons();
  }

  /* ============================
     DESKTOP LOGIN DROPDOWN
     ============================ */
  const loginDropdownContainer = document.getElementById('login-dropdown-container');
  const loginDropdownBtn = document.getElementById('login-dropdown-btn');
  const loginDropdownMenu = document.getElementById('login-dropdown-menu');
  const loginChevron = document.getElementById('login-chevron');

  function toggleLoginDropdown(open) {
    if (!loginDropdownMenu || !loginDropdownBtn) return;

    const isCurrentlyOpen = !loginDropdownMenu.classList.contains('hidden');
    const shouldOpen = open !== undefined ? open : !isCurrentlyOpen;

    if (shouldOpen) {
      loginDropdownMenu.classList.remove('hidden');
      loginDropdownMenu.classList.add('animate-dropdown');
      if (loginChevron) loginChevron.classList.add('rotate-180');
      loginDropdownBtn.setAttribute('aria-expanded', 'true');
    } else {
      loginDropdownMenu.classList.add('hidden');
      loginDropdownMenu.classList.remove('animate-dropdown');
      if (loginChevron) loginChevron.classList.remove('rotate-180');
      loginDropdownBtn.setAttribute('aria-expanded', 'false');
    }
  }

  if (loginDropdownBtn && loginDropdownMenu) {
    // Toggle on button click
    loginDropdownBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleLoginDropdown();
    });

    // Keyboard support (Escape closes)
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        toggleLoginDropdown(false);
      }
    });

    // Close dropdown on outside click
    document.addEventListener('click', (e) => {
      if (loginDropdownContainer && !loginDropdownContainer.contains(e.target)) {
        toggleLoginDropdown(false);
      }
    });

    // Close dropdown on option click
    loginDropdownMenu.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        toggleLoginDropdown(false);
      });
    });
  }

  /* ============================
     MOBILE MENU TOGGLE
     ============================ */
  const mobileMenuBtn = document.getElementById('mobile-menu-btn');
  const mobileMenu = document.getElementById('mobile-menu');
  const mobileMenuIcon = document.getElementById('mobile-menu-icon');

  function toggleMobileMenu(open) {
    if (!mobileMenu || !mobileMenuBtn) return;

    const isCurrentlyOpen = !mobileMenu.classList.contains('hidden');
    const shouldOpen = open !== undefined ? open : !isCurrentlyOpen;

    if (shouldOpen) {
      mobileMenu.classList.remove('hidden');
      document.body.classList.add('menu-open');
      mobileMenuBtn.setAttribute('aria-expanded', 'true');

      if (mobileMenuIcon && typeof lucide !== 'undefined') {
        mobileMenuIcon.setAttribute('data-lucide', 'x');
        lucide.createIcons({ targets: [mobileMenuIcon] });
      }
    } else {
      mobileMenu.classList.add('hidden');
      document.body.classList.remove('menu-open');
      mobileMenuBtn.setAttribute('aria-expanded', 'false');

      if (mobileMenuIcon && typeof lucide !== 'undefined') {
        mobileMenuIcon.setAttribute('data-lucide', 'menu');
        lucide.createIcons({ targets: [mobileMenuIcon] });
      }
    }
  }

  if (mobileMenuBtn && mobileMenu) {
    // Toggle on button click
    mobileMenuBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleMobileMenu();
    });

    // Close mobile menu on any link click
    mobileMenu.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        toggleMobileMenu(false);
      });
    });

    // Close mobile menu when clicking outside
    document.addEventListener('click', (e) => {
      if (
        mobileMenu &&
        !mobileMenu.classList.contains('hidden') &&
        !mobileMenu.contains(e.target) &&
        !mobileMenuBtn.contains(e.target)
      ) {
        toggleMobileMenu(false);
      }
    });

    // Close mobile menu on Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        toggleMobileMenu(false);
      }
    });

    // Close mobile menu on window resize to desktop
    window.addEventListener('resize', () => {
      if (window.innerWidth >= 768) {
        toggleMobileMenu(false);
      }
    });
  }

  /* ============================
     SMOOTH SCROLL TO TARGET
     ============================ */
  const scrollTriggers = document.querySelectorAll('[data-scroll-to]');
  scrollTriggers.forEach(trigger => {
    trigger.addEventListener('click', (e) => {
      e.preventDefault();
      const targetId = trigger.getAttribute('data-scroll-to');
      const targetElement = document.getElementById(targetId);
      if (targetElement) {
        targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  /* ============================
     SMOOTH SCROLL FOR HASH LINKS (#contact)
     ============================ */
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      const targetId = this.getAttribute('href');
      if (targetId === '#' || targetId === '') return;

      const targetElement = document.querySelector(targetId);
      if (targetElement) {
        e.preventDefault();
        targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Close mobile menu if open
        toggleMobileMenu(false);
      }
    });
  });
});