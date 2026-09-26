<?php
// Start session and include database connection
session_start();
require_once 'db_connect.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Rajagiri College of Social Sciences - Grievance Redressal Portal. Submit and track grievances securely.">
  <title>Rajagiri College of Social Sciences - Grievance Redressal Portal</title>
  <link rel="icon" type="image/svg+xml" href="public/favicon.svg">

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Lucide Icons CDN -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <!-- Google Fonts: Poppins used only as a fallback until the licensed Coolvetica
       font files are added to assets/fonts/ (see comment in the @font-face block below) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Custom Tailwind Theme Config -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            teal: {
              50:  '#EAF4F4',
              100: '#CFE6E7',
              200: '#9FCDCF',
              300: '#6FB4B7',
              400: '#3F9B9F',
              500: '#128287',
              600: '#006E74',   // brand primary
              700: '#005A5F',
              800: '#00454A',
              900: '#003134'
            }
          },
          fontFamily: {
            display: ['Coolvetica', 'Poppins', 'sans-serif'],
            sans: ['Coolvetica', 'Poppins', 'sans-serif']
          }
        }
      }
    }
  </script>

  <!--
    COOLVETICA FONT
    Coolvetica is a licensed display typeface and isn't available on a free CDN,
    so it can't be pulled in automatically. Drop the licensed files into
    assets/fonts/ with these exact names (or edit the paths below) and every
    piece of text on this page will render in Coolvetica. Until then, the page
    falls back to Poppins so it still looks clean.
  -->
  <style>
    @font-face {
      font-family: 'Coolvetica';
      src: url('assets/fonts/coolvetica-rg.woff2') format('woff2'),
           url('assets/fonts/coolvetica-rg.woff') format('woff');
      font-weight: 400;
      font-display: swap;
    }
    @font-face {
      font-family: 'Coolvetica';
      src: url('assets/fonts/coolvetica-bold.woff2') format('woff2'),
           url('assets/fonts/coolvetica-bold.woff') format('woff');
      font-weight: 700;
      font-display: swap;
    }

    html { scroll-behavior: smooth; }
    body { font-family: 'Coolvetica', 'Poppins', sans-serif; }

    /* Subtle dot-grid texture for the hero band, drawn with pure CSS (no images) */
    .hero-dots {
      background-image: radial-gradient(rgba(255,255,255,0.35) 1.5px, transparent 1.5px);
      background-size: 22px 22px;
    }

    /* Thin campus-roofline motif used as a footer divider */
    .roofline {
      height: 14px;
      background-image: linear-gradient(45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%),
                         linear-gradient(-45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%);
      background-size: 20px 14px;
      background-repeat: repeat-x;
    }

    .logo-divider { width: 1px; background-color: #CFE6E7; }
  </style>
</head>
<body class="min-h-screen bg-white text-teal-900 antialiased selection:bg-teal-100 selection:text-teal-700">

  <!-- Header -->
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

        <!-- Desktop Navigation -->
        <nav class="hidden md:flex items-center gap-8">
          <a
            href="contact.php"
            class="relative text-teal-900 hover:text-teal-600 transition-colors text-sm font-medium"
          >
            Contact
          </a>

          <!-- Login Dropdown -->
          <div class="relative" id="login-dropdown-container">
            <button
              id="login-dropdown-btn"
              type="button"
              aria-haspopup="true"
              aria-expanded="false"
              aria-controls="login-dropdown-menu"
              class="bg-teal-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-teal-700 transition-colors duration-200 flex items-center gap-2 cursor-pointer"
            >
              <span>Login</span>
              <i data-lucide="chevron-down" id="login-chevron" class="w-4 h-4 transition-transform duration-200"></i>
            </button>

            <div id="login-dropdown-menu" class="hidden absolute right-0 mt-2 w-72 bg-white rounded-xl shadow-xl border border-teal-100 z-50 py-2 overflow-hidden" role="menu">
              <div class="px-4 py-3 bg-teal-600">
                <p class="text-white text-xs font-semibold uppercase tracking-wide">Login as</p>
              </div>
              <a href="login.php?role=admin" class="group flex items-center gap-3 px-4 py-3 text-sm text-teal-900 hover:bg-teal-50 transition-colors border-b border-teal-50" role="menuitem">
                <i data-lucide="lock" class="w-4 h-4 text-teal-600"></i>
                <span>Admin</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>
              <a href="login.php?role=management" class="group flex items-center gap-3 px-4 py-3 text-sm text-teal-900 hover:bg-teal-50 transition-colors border-b border-teal-50" role="menuitem">
                <i data-lucide="layers" class="w-4 h-4 text-teal-600"></i>
                <span>Grievance Member</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>
              <a href="login.php?role=staff" class="group flex items-center gap-3 px-4 py-3 text-sm text-teal-900 hover:bg-teal-50 transition-colors border-b border-teal-50" role="menuitem">
                <i data-lucide="briefcase" class="w-4 h-4 text-teal-600"></i>
                <span>Teachers & Non-Teaching Staff</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>
              <a href="login.php?role=parent" class="group flex items-center gap-3 px-4 py-3 text-sm text-teal-900 hover:bg-teal-50 transition-colors border-b border-teal-50" role="menuitem">
                <i data-lucide="users" class="w-4 h-4 text-teal-600"></i>
                <span>Parents</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>
              <a href="login.php?role=student" class="group flex items-center gap-3 px-4 py-3 text-sm text-teal-900 hover:bg-teal-50 transition-colors" role="menuitem">
                <i data-lucide="graduation-cap" class="w-4 h-4 text-teal-600"></i>
                <span>Students</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>
            </div>
          </div>
        </nav>

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
      <div class="px-4 py-4 space-y-1">
        <a href="contact.php" class="block text-teal-900 hover:text-teal-600 text-sm font-medium px-4 py-3 hover:bg-teal-50 rounded-lg transition-colors">
          Contact
        </a>

        <div class="border-t border-teal-100 pt-3 mt-3">
          <p class="text-xs font-semibold text-teal-400 uppercase tracking-wide mb-2 px-4">Login as</p>
          <a href="login.php?role=admin" class="flex items-center gap-3 px-4 py-3 text-teal-900 hover:text-teal-600 hover:bg-teal-50 rounded-lg text-sm transition-colors">
            <i data-lucide="lock" class="w-4 h-4 text-teal-600"></i><span>Admin</span>
          </a>
          <a href="login.php?role=management" class="flex items-center gap-3 px-4 py-3 text-teal-900 hover:text-teal-600 hover:bg-teal-50 rounded-lg text-sm transition-colors">
            <i data-lucide="layers" class="w-4 h-4 text-teal-600"></i><span>Grievance Member</span>
          </a>
          <a href="login.php?role=staff" class="flex items-center gap-3 px-4 py-3 text-teal-900 hover:text-teal-600 hover:bg-teal-50 rounded-lg text-sm transition-colors">
            <i data-lucide="briefcase" class="w-4 h-4 text-teal-600"></i><span>Teachers & Non-Teaching Staff</span>
          </a>
          <a href="login.php?role=parent" class="flex items-center gap-3 px-4 py-3 text-teal-900 hover:text-teal-600 hover:bg-teal-50 rounded-lg text-sm transition-colors">
            <i data-lucide="users" class="w-4 h-4 text-teal-600"></i><span>Parents</span>
          </a>
          <a href="login.php?role=student" class="flex items-center gap-3 px-4 py-3 text-teal-900 hover:text-teal-600 hover:bg-teal-50 rounded-lg text-sm transition-colors">
            <i data-lucide="graduation-cap" class="w-4 h-4 text-teal-600"></i><span>Students</span>
          </a>
        </div>
      </div>
    </div>
  </header>

  <!-- Hero Banner -->
  <section class="relative overflow-hidden bg-teal-600">
    <div class="absolute inset-0 hero-dots opacity-40 pointer-events-none"></div>

    <div class="relative max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16 md:py-24 text-center">
      <div class="inline-flex items-center gap-2 bg-white/10 border border-white/25 rounded-full px-4 py-1.5 mb-7">
        <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
        <span class="text-white text-xs font-medium tracking-wide">Rajagiri College of Social Sciences</span>
      </div>

      <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-white mb-6 leading-[1.1]">
        Your voice deserves<br class="hidden sm:block"> a fair hearing
      </h1>

      <p class="text-base md:text-lg text-teal-50 mb-10 max-w-2xl mx-auto leading-relaxed">
        Submit a grievance, follow every step of its review and get a resolution you can trust — all in one secure portal built for students, parents and staff.
      </p>

      <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
        <button
          data-scroll-to="choose-portal"
          class="inline-flex items-center gap-2 bg-white text-teal-700 px-7 py-3.5 rounded-lg text-base font-semibold hover:bg-teal-50 transition-colors duration-200 cursor-pointer"
        >
          <i data-lucide="file-text" class="w-5 h-5"></i>
          <span>File a grievance</span>
        </button>
        <a
          href="#choose-portal"
          class="inline-flex items-center gap-2 border border-white/40 text-white px-7 py-3.5 rounded-lg text-base font-semibold hover:bg-white/10 transition-colors duration-200"
        >
          <span>Track a grievance</span>
          <i data-lucide="arrow-right" class="w-5 h-5"></i>
        </a>
      </div>
    </div>
  </section>

  <!-- Role Selection Cards -->
  <section id="choose-portal" class="py-16 md:py-24 bg-white scroll-mt-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="max-w-2xl mb-12">
        <h2 class="text-3xl md:text-4xl font-bold text-teal-900 mb-4">
          Choose your portal
        </h2>
        <p class="text-base md:text-lg text-teal-900/70 leading-relaxed">
          Every role sees a portal built around what it actually needs — pick yours to file, track or manage a grievance.
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-5">

        <!-- Student Portal Card -->
        <a href="login.php?role=student" class="group flex flex-col p-6 rounded-2xl border-2 border-teal-100 hover:border-teal-600 hover:shadow-lg transition-all duration-200">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-teal-50 text-teal-600 mb-5 group-hover:bg-teal-600 group-hover:text-white transition-colors duration-200">
            <i data-lucide="graduation-cap" class="w-6 h-6"></i>
          </div>
          <h3 class="text-base font-bold text-teal-900 mb-1">Student</h3>
          <p class="text-xs text-teal-900/60 font-medium mb-4">View & track grievances</p>
          <p class="text-sm text-teal-900/70 leading-relaxed mb-6 flex-1">
            Submit and follow up on grievances as an enrolled student.
          </p>
          <span class="inline-flex items-center gap-1.5 text-teal-600 font-semibold text-sm">
            Access portal
            <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform duration-200"></i>
          </span>
        </a>

        <!-- Parent Portal Card -->
        <a href="login.php?role=parent" class="group flex flex-col p-6 rounded-2xl border-2 border-teal-100 hover:border-teal-600 hover:shadow-lg transition-all duration-200">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-teal-50 text-teal-600 mb-5 group-hover:bg-teal-600 group-hover:text-white transition-colors duration-200">
            <i data-lucide="users" class="w-6 h-6"></i>
          </div>
          <h3 class="text-base font-bold text-teal-900 mb-1">Parent</h3>
          <p class="text-xs text-teal-900/60 font-medium mb-4">Monitor ward progress</p>
          <p class="text-sm text-teal-900/70 leading-relaxed mb-6 flex-1">
            Submit and monitor grievances raised on behalf of your ward.
          </p>
          <span class="inline-flex items-center gap-1.5 text-teal-600 font-semibold text-sm">
            Access portal
            <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform duration-200"></i>
          </span>
        </a>

        <!-- Teachers & Non-Teaching Staffs Card -->
        <a href="login.php?role=staff" class="group flex flex-col p-6 rounded-2xl border-2 border-teal-100 hover:border-teal-600 hover:shadow-lg transition-all duration-200">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-teal-50 text-teal-600 mb-5 group-hover:bg-teal-600 group-hover:text-white transition-colors duration-200">
            <i data-lucide="briefcase" class="w-6 h-6"></i>
          </div>
          <h3 class="text-base font-bold text-teal-900 mb-1">Teachers & Staff</h3>
          <p class="text-xs text-teal-900/60 font-medium mb-4">Priority resolution</p>
          <p class="text-sm text-teal-900/70 leading-relaxed mb-6 flex-1">
            Submit and manage grievances for teaching and non-teaching staff.
          </p>
          <span class="inline-flex items-center gap-1.5 text-teal-600 font-semibold text-sm">
            Access portal
            <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform duration-200"></i>
          </span>
        </a>

        <!-- Grievance Member Card -->
        <a href="login.php?role=management" class="group flex flex-col p-6 rounded-2xl border-2 border-teal-100 hover:border-teal-600 hover:shadow-lg transition-all duration-200">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-teal-50 text-teal-600 mb-5 group-hover:bg-teal-600 group-hover:text-white transition-colors duration-200">
            <i data-lucide="layers" class="w-6 h-6"></i>
          </div>
          <h3 class="text-base font-bold text-teal-900 mb-1">Grievance Member</h3>
          <p class="text-xs text-teal-900/60 font-medium mb-4">Committee dashboard</p>
          <p class="text-sm text-teal-900/70 leading-relaxed mb-6 flex-1">
            Review, resolve and oversee grievances as a committee member.
          </p>
          <span class="inline-flex items-center gap-1.5 text-teal-600 font-semibold text-sm">
            Access portal
            <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform duration-200"></i>
          </span>
        </a>

        <!-- Admin Portal Card -->
        <a href="login.php?role=admin" class="group flex flex-col p-6 rounded-2xl border-2 border-teal-100 hover:border-teal-600 hover:shadow-lg transition-all duration-200">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-teal-50 text-teal-600 mb-5 group-hover:bg-teal-600 group-hover:text-white transition-colors duration-200">
            <i data-lucide="lock" class="w-6 h-6"></i>
          </div>
          <h3 class="text-base font-bold text-teal-900 mb-1">Admin</h3>
          <p class="text-xs text-teal-900/60 font-medium mb-4">Full system control</p>
          <p class="text-sm text-teal-900/70 leading-relaxed mb-6 flex-1">
            Configure the system and manage users with full oversight.
          </p>
          <span class="inline-flex items-center gap-1.5 text-teal-600 font-semibold text-sm">
            Access portal
            <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform duration-200"></i>
          </span>
        </a>

      </div>
    </div>
  </section>

  <!-- Key Highlights -->
  <section class="py-16 md:py-24 bg-teal-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="max-w-2xl mb-12">
        <h2 class="text-3xl md:text-4xl font-bold text-teal-900 mb-4">
          Why this portal
        </h2>
        <p class="text-base md:text-lg text-teal-900/70 leading-relaxed">
          Built on the same principles that guide the college itself: fairness, transparency and follow-through.
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-x-10 gap-y-10">

        <div class="flex gap-5 pb-8 border-b border-teal-200/60">
          <div class="shrink-0 inline-flex items-center justify-center w-12 h-12 rounded-xl bg-white text-teal-600">
            <i data-lucide="shield" class="w-6 h-6"></i>
          </div>
          <div>
            <h3 class="text-lg font-bold text-teal-900 mb-1.5">100% confidentiality</h3>
            <p class="text-sm text-teal-900/70 leading-relaxed">Your identity and complaint details are protected with encryption at every step of the process.</p>
          </div>
        </div>

        <div class="flex gap-5 pb-8 border-b border-teal-200/60">
          <div class="shrink-0 inline-flex items-center justify-center w-12 h-12 rounded-xl bg-white text-teal-600">
            <i data-lucide="check-circle" class="w-6 h-6"></i>
          </div>
          <div>
            <h3 class="text-lg font-bold text-teal-900 mb-1.5">UGC norms compliant</h3>
            <p class="text-sm text-teal-900/70 leading-relaxed">Fully aligned with University Grants Commission guidelines and regulatory requirements.</p>
          </div>
        </div>

        <div class="flex gap-5 pb-8 md:pb-0 border-b md:border-b-0 border-teal-200/60">
          <div class="shrink-0 inline-flex items-center justify-center w-12 h-12 rounded-xl bg-white text-teal-600">
            <i data-lucide="layers" class="w-6 h-6"></i>
          </div>
          <div>
            <h3 class="text-lg font-bold text-teal-900 mb-1.5">Two-tier resolution</h3>
            <p class="text-sm text-teal-900/70 leading-relaxed">A structured escalation path that ensures thorough review and a fair outcome at every level.</p>
          </div>
        </div>

        <div class="flex gap-5">
          <div class="shrink-0 inline-flex items-center justify-center w-12 h-12 rounded-xl bg-white text-teal-600">
            <i data-lucide="clock" class="w-6 h-6"></i>
          </div>
          <div>
            <h3 class="text-lg font-bold text-teal-900 mb-1.5">Clear resolution timelines</h3>
            <p class="text-sm text-teal-900/70 leading-relaxed">Committed turnaround times with transparent, regularly updated progress tracking.</p>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="bg-teal-900 text-white">
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
              <span>grievance@rajagiricss.edu</span>
            </li>
            <li class="flex items-center gap-2">
              <i data-lucide="phone" class="w-4 h-4 text-teal-300"></i>
              <span>+91 484 XXX XXXX</span>
            </li>
            <li class="flex items-center gap-2">
              <i data-lucide="map-pin" class="w-4 h-4 text-teal-300"></i>
              <span>Aluva, Kochi, Kerala</span>
            </li>
          </ul>
        </div>
      </div>

      <div class="border-t border-white/10 pt-6 text-center text-xs text-teal-200/70">
        <p>&copy; <?php echo date('Y'); ?> Rajagiri College of Social Sciences. All rights reserved.</p>
      </div>
    </div>
  </footer>

  <!-- Page Scripts -->
  <script>
    // Initialize icons
    lucide.createIcons();

    // Mobile menu toggle
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    const mobileMenu = document.getElementById('mobile-menu');
    const mobileMenuIcon = document.getElementById('mobile-menu-icon');
    mobileMenuBtn.addEventListener('click', () => {
      const isHidden = mobileMenu.classList.toggle('hidden');
      mobileMenuBtn.setAttribute('aria-expanded', String(!isHidden));
      mobileMenuIcon.setAttribute('data-lucide', isHidden ? 'menu' : 'x');
      lucide.createIcons();
    });

    // Login dropdown toggle (desktop)
    const loginBtn = document.getElementById('login-dropdown-btn');
    const loginMenu = document.getElementById('login-dropdown-menu');
    const loginChevron = document.getElementById('login-chevron');
    loginBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isHidden = loginMenu.classList.toggle('hidden');
      loginBtn.setAttribute('aria-expanded', String(!isHidden));
      loginChevron.style.transform = isHidden ? 'rotate(0deg)' : 'rotate(180deg)';
    });
    document.addEventListener('click', (e) => {
      if (!document.getElementById('login-dropdown-container').contains(e.target)) {
        loginMenu.classList.add('hidden');
        loginBtn.setAttribute('aria-expanded', 'false');
        loginChevron.style.transform = 'rotate(0deg)';
      }
    });

    // Smooth scroll for data-scroll-to buttons
    document.querySelectorAll('[data-scroll-to]').forEach((el) => {
      el.addEventListener('click', () => {
        const target = document.getElementById(el.getAttribute('data-scroll-to'));
        if (target) target.scrollIntoView({ behavior: 'smooth' });
      });
    });
  </script>
</body>
</html>