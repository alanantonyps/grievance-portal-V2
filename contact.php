<?php
/**
 * contact.php
 * ---------------------------------------------------------------------------
 * Contact Us — Rajagiri College Grievance Redressal Portal
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Contact the Rajagiri College Grievance Redressal team — email, phone, and campus location.">
  <title>Contact Us — Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="public/favicon.svg" />

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            teal: {
              50:'#EAF4F4',100:'#CFE6E7',200:'#9FCDCF',300:'#6FB4B7',400:'#3F9B9F',
              500:'#128287',600:'#006E74',700:'#005A5F',800:'#00454A',900:'#003134'
            }
          },
          fontFamily: {
            display: ['Coolvetica', 'Poppins', 'sans-serif'],
            sans: ['Coolvetica', 'Poppins', 'sans-serif']
          },
          keyframes: {
            fadeInUp: { '0%': { opacity: '0', transform: 'translateY(12px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
            swingA: { '0%, 100%': { transform: 'rotate(-6deg)' }, '50%': { transform: 'rotate(6deg)' } },
            swingB: { '0%, 100%': { transform: 'rotate(5deg)' },  '50%': { transform: 'rotate(-5deg)' } },
            swingC: { '0%, 100%': { transform: 'rotate(-4deg)' }, '50%': { transform: 'rotate(4deg)' } },
            swingD: { '0%, 100%': { transform: 'rotate(7deg)' },  '50%': { transform: 'rotate(-7deg)' } }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'swing-a': 'swingA 3.2s ease-in-out infinite',
            'swing-b': 'swingB 3.6s ease-in-out infinite',
            'swing-c': 'swingC 3.0s ease-in-out infinite',
            'swing-d': 'swingD 3.8s ease-in-out infinite'
          }
        }
      }
    };
  </script>

  <style>
    @font-face {
      font-family: 'Coolvetica';
      src: url('assets/fonts/coolvetica-rg.woff2') format('woff2'),
           url('assets/fonts/coolvetica-rg.woff') format('woff');
      font-weight: 400; font-display: swap;
    }
    @font-face {
      font-family: 'Coolvetica';
      src: url('assets/fonts/coolvetica-bold.woff2') format('woff2'),
           url('assets/fonts/coolvetica-bold.woff') format('woff');
      font-weight: 700; font-display: swap;
    }
    html { scroll-behavior: smooth; }
    body { font-family: 'Coolvetica', 'Poppins', sans-serif; }

    /* Subtle dot-grid texture for the hero band */
    .hero-dots {
      background-image: radial-gradient(rgba(255,255,255,0.35) 1.5px, transparent 1.5px);
      background-size: 22px 22px;
    }
    /* Thin campus-roofline motif used as a footer divider */
    .roofline {
      height: 14px;
      background-image: linear-gradient(45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%),
                        linear-gradient(-45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%);
      background-size: 20px 14px; background-repeat: repeat-x;
    }
    .logo-divider { width: 1px; background-color: #CFE6E7; }
  </style>
</head>

<body class="min-h-screen bg-white text-teal-900 antialiased selection:bg-teal-100 selection:text-teal-700 flex flex-col">

  <!-- ====================== HEADER ====================== -->
  <header class="bg-white sticky top-0 z-50 border-b-2 border-teal-600">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between items-center h-16 md:h-20">

        <!-- Logo -->
        <a href="index.php" class="flex items-center gap-3 md:gap-4 shrink-0">
          <img
            src="public/rcss-logo.webp"
            alt="Rajagiri College of Social Sciences"
            class="h-8 md:h-10 w-auto"
          />
          <span class="hidden sm:block logo-divider h-8 md:h-10"></span>
          <span class="hidden sm:flex items-baseline gap-1">
            <span class="text-xl md:text-2xl font-bold text-teal-600 tracking-tight">grievance</span>
            <span class="w-1.5 h-1.5 rounded-full bg-teal-600 mb-1"></span>
          </span>
        </a>

        <!-- Desktop: Back to Home -->
        <a
          href="index.php"
          class="group hidden md:inline-flex items-center gap-2 text-teal-900 hover:text-teal-600 transition-colors text-sm font-medium"
        >
          <i data-lucide="arrow-left" class="w-4 h-4 transition-transform group-hover:-translate-x-1"></i>
          <span>Back to Home</span>
        </a>

        <!-- Mobile Menu Button -->
        <button
          id="mobile-menu-btn"
          type="button"
          aria-label="Toggle menu"
          aria-expanded="false"
          aria-controls="mobile-menu"
          class="md:hidden text-teal-600 hover:bg-teal-50 p-2 rounded-lg transition-colors cursor-pointer"
        >
          <i data-lucide="menu" id="mobile-menu-icon" class="w-6 h-6"></i>
        </button>
      </div>
    </div>

    <!-- Mobile Menu Drawer -->
    <div id="mobile-menu" class="hidden md:hidden bg-white border-t-2 border-teal-100 shadow-lg">
      <div class="px-4 py-4">
        <a
          href="index.php"
          class="flex items-center gap-3 px-4 py-3 rounded-lg text-teal-900 hover:text-teal-600 hover:bg-teal-50 transition-colors text-sm font-medium"
        >
          <i data-lucide="arrow-left" class="w-4 h-4"></i>
          <span>Back to Home</span>
        </a>
      </div>
    </div>
  </header>

  <!-- ====================== HERO BANNER ====================== -->
  <section class="relative overflow-hidden bg-teal-600">
    <div class="absolute inset-0 hero-dots opacity-40 pointer-events-none"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 md:py-20">
      <div class="flex flex-col md:flex-row items-center justify-between gap-10 md:gap-12">

        <!-- Left: heading -->
        <div class="text-center md:text-left animate-fade-in-up">
          <div class="inline-flex items-center gap-2 bg-white/10 border border-white/25 rounded-full px-4 py-1.5 mb-6">
            <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
            <span class="text-white text-xs font-medium tracking-wide">We're Here to Help</span>
          </div>

          <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-white mb-4 leading-[1.1]">
            Contact Us
          </h1>
          <p class="text-base md:text-lg text-teal-50 max-w-xl mx-auto md:mx-0 leading-relaxed">
            Have a question, feedback, or need support with the grievance portal?
            Reach out to the RCSS Grievance Redressal team — we're happy to assist.
          </p>

          <div class="mt-8 flex flex-wrap items-center justify-center md:justify-start gap-3">
            <a href="#map" class="inline-flex items-center gap-2 px-7 py-3.5 rounded-lg
                                 bg-white text-teal-700 font-semibold text-base
                                 hover:bg-teal-50 transition-colors duration-200">
              <i data-lucide="map-pin" class="w-5 h-5"></i>
              <span>View Location</span>
            </a>
            <a href="index.php" class="inline-flex items-center gap-2 px-7 py-3.5 rounded-lg
                                      border border-white/40 text-white font-semibold text-base
                                      hover:bg-white/10 transition-colors duration-200">
              <i data-lucide="home" class="w-5 h-5"></i>
              <span>Back to Home</span>
            </a>
          </div>
        </div>

        <!-- Right: hanging icons -->
        <div class="relative flex items-start justify-center gap-4 sm:gap-6 md:gap-8 pt-4 md:pt-2">

          <!-- Icon 1: Mail -->
          <div class="flex flex-col items-center origin-top animate-swing-a">
            <div class="w-[2px] h-12 md:h-16 bg-gradient-to-b from-white/70 to-white/20"></div>
            <div class="relative -mt-1">
              <div class="absolute inset-0 bg-teal-800 rounded-full blur-md opacity-70"></div>
              <div class="relative w-16 h-16 md:w-20 md:h-20 rounded-full
                          bg-white
                          flex items-center justify-center shadow-xl
                          ring-2 ring-white/50">
                <i data-lucide="mail" class="w-7 h-7 md:w-9 md:h-9 text-teal-600"></i>
              </div>
            </div>
          </div>

          <!-- Icon 2: Phone -->
          <div class="flex flex-col items-center origin-top animate-swing-b mt-2">
            <div class="w-[2px] h-12 md:h-16 bg-gradient-to-b from-white/70 to-white/20"></div>
            <div class="relative -mt-1">
              <div class="absolute inset-0 bg-teal-800 rounded-full blur-md opacity-70"></div>
              <div class="relative w-16 h-16 md:w-20 md:h-20 rounded-full
                          bg-white
                          flex items-center justify-center shadow-xl
                          ring-2 ring-white/50">
                <i data-lucide="phone" class="w-7 h-7 md:w-9 md:h-9 text-teal-600"></i>
              </div>
            </div>
          </div>

          <!-- Icon 3: Mobile -->
          <div class="flex flex-col items-center origin-top animate-swing-c mt-4">
            <div class="w-[2px] h-12 md:h-16 bg-gradient-to-b from-white/70 to-white/20"></div>
            <div class="relative -mt-1">
              <div class="absolute inset-0 bg-teal-800 rounded-full blur-md opacity-70"></div>
              <div class="relative w-16 h-16 md:w-20 md:h-20 rounded-full
                          bg-white
                          flex items-center justify-center shadow-xl
                          ring-2 ring-white/50">
                <i data-lucide="smartphone" class="w-7 h-7 md:w-9 md:h-9 text-teal-600"></i>
              </div>
            </div>
          </div>

          <!-- Icon 4: @ Symbol -->
          <div class="flex flex-col items-center origin-top animate-swing-d mt-1">
            <div class="w-[2px] h-12 md:h-16 bg-gradient-to-b from-white/70 to-white/20"></div>
            <div class="relative -mt-1">
              <div class="absolute inset-0 bg-teal-800 rounded-full blur-md opacity-70"></div>
              <div class="relative w-16 h-16 md:w-20 md:h-20 rounded-full
                          bg-white
                          flex items-center justify-center shadow-xl
                          ring-2 ring-white/50">
                <span class="text-teal-600 text-3xl md:text-4xl font-bold leading-none">@</span>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </section>

  <!-- ====================== ADDRESS CARD ====================== -->
  <section class="relative -mt-8 md:-mt-12 z-10">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">
      <div class="bg-white border-2 border-teal-100 rounded-3xl shadow-xl px-6 py-8 md:px-10 md:py-10 text-center animate-fade-in-up">

        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl
                    bg-teal-600 shadow-sm mb-4">
          <i data-lucide="map-pin" class="w-7 h-7 text-white"></i>
        </div>

        <h2 class="text-2xl md:text-3xl font-bold text-teal-900 mb-4">
          Contact Address
        </h2>

        <p class="text-base md:text-lg font-semibold text-teal-900">
          Rajagiri College of Social Sciences
        </p>
        <p class="text-sm md:text-base text-teal-900/70 mt-1 leading-relaxed">
          Rajagiri P.O., Kalamassery,<br class="sm:hidden" />
          Cochin – 683 104, Kerala, India
        </p>

        <div class="mt-6 flex items-center justify-center">
          <a href="https://www.google.com/maps/dir/?api=1&destination=Rajagiri+College+of+Social+Sciences+Kalamassery"
             target="_blank" rel="noopener"
             class="inline-flex items-center gap-2 px-7 py-3.5 rounded-lg
                    bg-teal-600 hover:bg-teal-700
                    text-white font-semibold text-base
                    shadow-sm hover:shadow-md transition-colors duration-200">
            <i data-lucide="navigation" class="w-5 h-5"></i>
            <span>Get Directions</span>
          </a>
        </div>

      </div>
    </div>
  </section>

  <!-- ====================== CONTACT INFO TILES ====================== -->
  <section class="pt-12 md:pt-16 pb-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="max-w-2xl mb-10 animate-fade-in-up">
        <div class="inline-flex items-center gap-2 mb-3">
          <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600"></span>
          <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Get in Touch</p>
        </div>
        <h2 class="text-3xl md:text-4xl font-bold text-teal-900 mb-3">Multiple ways to reach us</h2>
        <p class="text-base md:text-lg text-teal-900/70 leading-relaxed">
          Choose the channel that works best for you — we're here to help.
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-5 lg:gap-6">

        <!-- Email -->
        <div class="group flex flex-col p-6 rounded-2xl border-2 border-teal-100 bg-white
                    hover:border-teal-600 hover:shadow-lg transition-all duration-200">

          <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-teal-50 text-teal-600 mb-5
                      group-hover:bg-teal-600 group-hover:text-white transition-colors duration-200">
            <i data-lucide="mail" class="w-6 h-6"></i>
          </div>

          <h3 class="text-base font-bold text-teal-900 mb-1">Email Us</h3>
          <p class="text-xs font-semibold text-teal-900/50 uppercase tracking-wider mb-4">Fastest Response</p>

          <div class="space-y-2 mt-auto">
            <a href="mailto:grievance@rajagiri.edu" class="flex items-center gap-2 text-sm text-teal-900/80 hover:text-teal-600 transition-colors">
              <i data-lucide="arrow-right" class="w-3.5 h-3.5 text-teal-600"></i>
              <span class="break-all">grievance@rajagiri.edu</span>
            </a>
            <a href="mailto:principal@rajagiri.edu" class="flex items-center gap-2 text-sm text-teal-900/80 hover:text-teal-600 transition-colors">
              <i data-lucide="arrow-right" class="w-3.5 h-3.5 text-teal-600"></i>
              <span class="break-all">principal@rajagiri.edu</span>
            </a>
          </div>
        </div>

        <!-- Phone -->
        <div class="group flex flex-col p-6 rounded-2xl border-2 border-teal-100 bg-white
                    hover:border-teal-600 hover:shadow-lg transition-all duration-200">

          <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-teal-50 text-teal-600 mb-5
                      group-hover:bg-teal-600 group-hover:text-white transition-colors duration-200">
            <i data-lucide="phone" class="w-6 h-6"></i>
          </div>

          <h3 class="text-base font-bold text-teal-900 mb-1">Call Us</h3>
          <p class="text-xs font-semibold text-teal-900/50 uppercase tracking-wider mb-4">Mon–Fri, 9 AM – 5 PM</p>

          <div class="space-y-2 mt-auto">
            <a href="tel:+914842554000" class="flex items-center gap-2 text-sm text-teal-900/80 hover:text-teal-600 transition-colors">
              <i data-lucide="arrow-right" class="w-3.5 h-3.5 text-teal-600"></i>
              <span>+91 484 255 4000</span>
            </a>
            <a href="tel:+914842554100" class="flex items-center gap-2 text-sm text-teal-900/80 hover:text-teal-600 transition-colors">
              <i data-lucide="arrow-right" class="w-3.5 h-3.5 text-teal-600"></i>
              <span>+91 484 255 4100</span>
            </a>
          </div>
        </div>

        <!-- Address -->
        <div class="group flex flex-col p-6 rounded-2xl border-2 border-teal-100 bg-white
                    hover:border-teal-600 hover:shadow-lg transition-all duration-200">

          <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-teal-50 text-teal-600 mb-5
                      group-hover:bg-teal-600 group-hover:text-white transition-colors duration-200">
            <i data-lucide="map-pin" class="w-6 h-6"></i>
          </div>

          <h3 class="text-base font-bold text-teal-900 mb-1">Visit Us</h3>
          <p class="text-xs font-semibold text-teal-900/50 uppercase tracking-wider mb-4">Campus Location</p>

          <p class="text-sm text-teal-900/80 leading-relaxed mt-auto">
            Rajagiri P.O., Kalamassery,<br />
            Cochin – 683 104,<br />
            Kerala, India
          </p>
        </div>

      </div>
    </div>
  </section>

  <!-- ====================== GOOGLE MAP ====================== -->
  <section id="map" class="pb-14 md:pb-20 scroll-mt-24 bg-teal-50/40 py-12 md:py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

      <div class="max-w-2xl mb-10 animate-fade-in-up">
        <div class="inline-flex items-center gap-2 mb-3">
          <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600"></span>
          <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Locate Us</p>
        </div>
        <h2 class="text-3xl md:text-4xl font-bold text-teal-900 mb-3">Find us on the map</h2>
        <p class="text-base md:text-lg text-teal-900/70 leading-relaxed">
          Rajagiri College of Social Sciences, Kalamassery, Kochi — open in Google Maps for directions.
        </p>
      </div>

      <div class="relative rounded-2xl overflow-hidden border-2 border-teal-100 shadow-sm bg-white">
        <iframe
          title="Rajagiri College of Social Sciences Location"
          src="https://www.google.com/maps?q=Rajagiri%20College%20of%20Social%20Sciences%20Kalamassery&output=embed"
          width="100%"
          height="480"
          style="border:0;"
          allowfullscreen=""
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"
          class="block w-full h-[360px] md:h-[480px]"
        ></iframe>

        <!-- Map overlay badge (top right) -->
        <div class="absolute top-4 right-4 bg-white rounded-xl shadow-md border border-teal-100 px-4 py-3 flex items-center gap-3 max-w-xs">
          <div class="w-10 h-10 rounded-lg bg-teal-600 flex items-center justify-center flex-shrink-0">
            <i data-lucide="school" class="w-5 h-5 text-white"></i>
          </div>
          <div class="min-w-0">
            <p class="text-xs font-bold text-teal-900 truncate">Rajagiri College of Social Sciences</p>
            <p class="text-[11px] text-teal-900/60 truncate">Kalamassery, Kochi, Kerala</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ====================== FOOTER ====================== -->
  <footer class="bg-teal-900 text-white mt-auto">
    <div class="roofline"></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-10 mb-10">
        <div>
          <div class="flex items-center gap-3 mb-4 bg-white rounded-lg px-3 py-2 w-fit">
            <img src="public/rcss-logo.webp" alt="Rajagiri College of Social Sciences" class="h-9 w-auto" />
          </div>
          <p class="text-teal-100/80 text-sm leading-relaxed max-w-xs">
            Rajagiri College of Social Sciences — committed to fairness, transparency and prompt grievance redressal.
          </p>
        </div>

        <div>
          <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-teal-200">Quick links</h4>
          <ul class="space-y-2.5 text-sm">
            <li><a href="login.php?role=student" class="text-teal-100/80 hover:text-white transition-colors">Student</a></li>
            <li><a href="login.php?role=parent" class="text-teal-100/80 hover:text-white transition-colors">Parent</a></li>
            <li><a href="login.php?role=staff" class="text-teal-100/80 hover:text-white transition-colors">Staff</a></li>
            <li><a href="login.php?role=management" class="text-teal-100/80 hover:text-white transition-colors">Grievance Member</a></li>
          </ul>
        </div>

        <div>
          <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-teal-200">Contact</h4>
          <ul class="space-y-3 text-sm text-teal-100/80">
            <li class="flex items-center gap-2">
              <i data-lucide="mail" class="w-4 h-4 text-teal-300"></i>
              <span>grievance@rajagiri.edu</span>
            </li>
            <li class="flex items-center gap-2">
              <i data-lucide="phone" class="w-4 h-4 text-teal-300"></i>
              <span>+91 484 255 4000</span>
            </li>
            <li class="flex items-center gap-2">
              <i data-lucide="map-pin" class="w-4 h-4 text-teal-300"></i>
              <span>Kalamassery, Kochi, Kerala</span>
            </li>
          </ul>
        </div>
      </div>

      <div class="border-t border-white/10 pt-6 text-center text-xs text-teal-200/70">
        <p>
          &copy; <?= date('Y') ?>
          <span class="font-bold text-white">Rajagiri College of Social Sciences</span>. All rights reserved.
        </p>
        <p class="mt-2">
          Powered by <span class="font-bold text-white">RLabZ</span>
        </p>
      </div>
    </div>
  </footer>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof lucide !== 'undefined') {
        lucide.createIcons();
      }

      // ---- Mobile menu toggle ----
      (function () {
        const btn  = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');
        const icon = document.getElementById('mobile-menu-icon');
        if (!btn || !menu) return;

        btn.addEventListener('click', function () {
          const isOpen = !menu.classList.contains('hidden');
          menu.classList.toggle('hidden');
          btn.setAttribute('aria-expanded', String(!isOpen));
          if (icon) {
            icon.setAttribute('data-lucide', isOpen ? 'menu' : 'x');
            if (typeof lucide !== 'undefined') lucide.createIcons({ targets: [icon] });
          }
        });
      })();

      window.scrollTo(0, 0);
    });
  </script>

  <script src="assets/js/index.js"></script>
</body>
</html>