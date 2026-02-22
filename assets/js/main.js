document.addEventListener("DOMContentLoaded", function () {
  // Initialize theme
  initTheme();

  // Initialize loading screen
  initLoadingScreen();

  // Initialize AOS (Animate On Scroll)
  if (typeof AOS !== "undefined") {
    AOS.init({
      duration: 800,
      once: true,
      offset: 100,
    });
  }

  // Initialize navbar scroll effect
  initNavbarScroll();

  // Initialize smooth scrolling
  initSmoothScrolling();

  // Initialize book actions
  initBookActions();

  // Initialize theme toggle
  initThemeToggle();

  // Initialize phone animations
  initPhoneAnimations();

  // Initialize floating elements
  initFloatingElements();

  // Initialize enhanced features
  initLazyLoading();
  setTimeout(initIntersectionObserver, 1000);
  initParallaxEffects();
  initMobileMenu();
  initScrollToTop();

  // Initialize book image error handling
  initBookImageErrorHandling();
});

// Theme Management
function initTheme() {
  const savedTheme = localStorage.getItem("abcLibraryTheme") || "light";
  setTheme(savedTheme);
}

function setTheme(theme) {
  document.documentElement.setAttribute("data-theme", theme);
  localStorage.setItem("abcLibraryTheme", theme);

  const themeIcon = document.getElementById("themeIcon");
  if (themeIcon) {
    if (theme === "dark") {
      themeIcon.className = "fas fa-sun";
    } else {
      themeIcon.className = "fas fa-moon";
    }
  }
}

function toggleTheme() {
  const currentTheme = document.documentElement.getAttribute("data-theme");
  const newTheme = currentTheme === "dark" ? "light" : "dark";
  setTheme(newTheme);

  // Show notification about theme change
  showNotification(`Switched to ${newTheme} mode`, "info");
}

function initThemeToggle() {
  const themeToggle = document.getElementById("themeToggle");
  if (themeToggle) {
    themeToggle.addEventListener("click", toggleTheme);
  }
}

// Enhanced Loading Screen
function initLoadingScreen() {
  const loadingScreen = document.getElementById("loading-screen");

  // Ensure minimum loading time for better UX
  const minLoadTime = 2500;
  const startTime = Date.now();

  const hideLoading = () => {
    const elapsedTime = Date.now() - startTime;
    const remainingTime = Math.max(0, minLoadTime - elapsedTime);

    setTimeout(() => {
      if (loadingScreen) {
        loadingScreen.style.transition = "opacity 0.5s ease-out";
        loadingScreen.style.opacity = "0";
        setTimeout(() => {
          loadingScreen.style.display = "none";
          // Add fade-in animation to body content
          document.body.classList.add("fade-in");

          // Trigger AOS refresh after content is visible
          if (typeof AOS !== "undefined") {
            AOS.refresh();
          }
        }, 500);
      }
    }, remainingTime);
  };

  // Hide loading screen when page is fully loaded
  if (document.readyState === "complete") {
    hideLoading();
  } else {
    window.addEventListener("load", hideLoading);
  }
}

// Enhanced Navbar Scroll Effect
function initNavbarScroll() {
  const navbar = document.getElementById("mainNavbar");

  let lastScrollY = window.scrollY;
  let isScrollingDown = false;

  const updateNavbar = () => {
    const currentScrollY = window.scrollY;
    isScrollingDown = currentScrollY > lastScrollY;

    if (currentScrollY > 100) {
      navbar.classList.add("scrolled");

      // Hide navbar when scrolling down, show when scrolling up
      if (isScrollingDown && currentScrollY > 200) {
        navbar.style.transform = "translateY(-100%)";
      } else {
        navbar.style.transform = "translateY(0)";
      }
    } else {
      navbar.classList.remove("scrolled");
      navbar.style.transform = "translateY(0)";
    }

    lastScrollY = currentScrollY;
  };

  window.addEventListener("scroll", throttle(updateNavbar, 100));
}

// Smooth Scrolling with offset
function initSmoothScrolling() {
  document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener("click", function (e) {
      e.preventDefault();
      const target = document.querySelector(this.getAttribute("href"));
      if (target) {
        const offsetTop = target.offsetTop - 80;
        window.scrollTo({
          top: offsetTop,
          behavior: "smooth",
        });

        // Close mobile menu if open
        const navbarCollapse = document.querySelector(".navbar-collapse");
        const navbarToggler = document.querySelector(".navbar-toggler");
        if (navbarCollapse && navbarCollapse.classList.contains("show")) {
          navbarCollapse.classList.remove("show");
          navbarToggler.classList.remove("active");
        }
      }
    });
  });
}

// Phone Animations
function initPhoneAnimations() {
  const bookItems = document.querySelectorAll(".book-item");

  bookItems.forEach((item, index) => {
    // Add staggered hover animations
    item.addEventListener("mouseenter", function () {
      this.style.animationDelay = `${index * 0.1}s`;
      this.classList.add("hover-animate");
    });

    item.addEventListener("mouseleave", function () {
      this.classList.remove("hover-animate");
    });
  });

  // Animate book covers every 4 seconds
  setInterval(() => {
    if (bookItems.length > 0) {
      const randomItem =
        bookItems[Math.floor(Math.random() * bookItems.length)];
      if (randomItem && !randomItem.matches(":hover")) {
        randomItem.classList.add("pulse-animate");
        setTimeout(() => {
          randomItem.classList.remove("pulse-animate");
        }, 1000);
      }
    }
  }, 4000);
}

// Floating Elements Animation
function initFloatingElements() {
  const floatingIcons = document.querySelectorAll(".floating-book-icon");

  floatingIcons.forEach((icon, index) => {
    // Add random gentle movement
    setInterval(() => {
      const randomX = (Math.random() - 0.5) * 6;
      const randomY = (Math.random() - 0.5) * 6;
      const currentTransform = icon.style.transform || "";

      // Apply movement without overriding existing transforms
      icon.style.transform =
        currentTransform + ` translate(${randomX}px, ${randomY}px)`;

      // Reset after animation
      setTimeout(() => {
        icon.style.transform = currentTransform;
      }, 2000);
    }, 3000 + index * 800);
  });
}

// Book Actions
function initBookActions() {
  console.log("ESSSL Library enhanced book actions initialized");

  // Add loading states for book action buttons
  document.addEventListener("click", function (e) {
    if (e.target.closest(".book-card .btn")) {
      const button = e.target.closest(".btn");
      const originalContent = button.innerHTML;

      // Add loading state
      button.disabled = true;
      button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

      // Reset after action completes
      setTimeout(() => {
        button.disabled = false;
        button.innerHTML = originalContent;
      }, 1500);
    }
  });
}

// Book Image Error Handling
function initBookImageErrorHandling() {
  // Handle book cover image loading errors
  document.querySelectorAll(".book-card img").forEach((img) => {
    img.addEventListener("error", function () {
      // Create a fallback placeholder
      this.src =
        "data:image/svg+xml;base64," +
        btoa(`
                <svg width="200" height="280" xmlns="http://www.w3.org/2000/svg">
                    <rect width="100%" height="100%" fill="#4A90E2"/>
                    <text x="50%" y="40%" text-anchor="middle" fill="white" font-size="16" font-family="Arial, sans-serif">
                        ESSSL Library
                    </text>
                    <text x="50%" y="60%" text-anchor="middle" fill="white" font-size="12" font-family="Arial, sans-serif">
                        Book Cover
                    </text>
                    <text x="50%" y="75%" text-anchor="middle" fill="white" font-size="10" font-family="Arial, sans-serif">
                        Not Available
                    </text>
                </svg>
            `);
    });

    // Add loading animation
    img.addEventListener("load", function () {
      this.classList.add("image-loaded");
    });
  });
}

function addToWishlist(bookId) {
  showNotification(`Added book ${bookId} to your wishlist!`, "success");

  // Add visual feedback with heart animation
  const heartIcon = event.target.closest("button").querySelector("i");
  if (heartIcon) {
    heartIcon.style.transform = "scale(1.5)";
    heartIcon.style.color = "#ef4444";
    heartIcon.className = "fas fa-heart"; // Change to filled heart

    // Create floating heart animation
    const floatingHeart = document.createElement("i");
    floatingHeart.className = "fas fa-heart";
    floatingHeart.style.cssText = `
            position: absolute;
            color: #ef4444;
            font-size: 1.2rem;
            z-index: 1000;
            pointer-events: none;
            animation: floatingHeart 2s ease-out forwards;
        `;

    const rect = heartIcon.getBoundingClientRect();
    floatingHeart.style.left = rect.left + "px";
    floatingHeart.style.top = rect.top + "px";

    document.body.appendChild(floatingHeart);

    setTimeout(() => {
      heartIcon.style.transform = "scale(1)";
      floatingHeart.remove();
    }, 2000);
  }
}

// Enhanced Quick Search
function quickSearch(query) {
  showNotification(`Searching for "${query}" in ESSSL Library...`, "info");
  setTimeout(() => {
    window.location.href = `dashboard?search=${encodeURIComponent(query)}`;
  }, 1000);
}

// Enhanced Notification System
function showNotification(message, type = "info") {
  const notification = document.createElement("div");
  notification.className = `alert alert-${type} alert-dismissible fade show position-fixed notification-custom`;
  notification.style.cssText = `
        top: 100px; 
        right: 20px; 
        z-index: 9999; 
        min-width: 300px;
        max-width: 400px;
        animation: slideInRight 0.3s ease-out;
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        border: none;
        border-radius: 12px;
        backdrop-filter: blur(10px);
    `;

  const icon = getNotificationIcon(type);
  notification.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="fas fa-${icon} me-2 fs-5"></i>
            <div class="flex-grow-1">${message}</div>
            <button type="button" class="btn-close" onclick="this.parentElement.parentElement.remove()"></button>
        </div>
    `;

  document.body.appendChild(notification);

  // Auto dismiss after 4 seconds
  setTimeout(() => {
    if (notification && notification.parentNode) {
      notification.style.animation = "slideOutRight 0.3s ease-in";
      setTimeout(() => {
        if (notification.parentNode) notification.remove();
      }, 300);
    }
  }, 4000);
}

function getNotificationIcon(type) {
  const icons = {
    success: "check-circle",
    info: "info-circle",
    warning: "exclamation-triangle",
    danger: "exclamation-circle",
    primary: "bell",
  };
  return icons[type] || "info-circle";
}

// Enhanced Book Search Function
function searchBooks(query) {
  return fetch(`api/search_books.php?q=${encodeURIComponent(query)}`)
    .then((response) => {
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      return response.json();
    })
    .then((data) => {
      if (data.success) {
        showNotification(
          `Found ${data.books.length} books matching "${query}"`,
          "success"
        );
        return data;
      } else {
        showNotification("No books found matching your search", "warning");
        return { success: false, books: [] };
      }
    })
    .catch((error) => {
      console.error("Search error:", error);
      showNotification("Search failed. Please try again later.", "danger");
      return { success: false, error: "Search failed" };
    });
}

// Enhanced Form Validation
function validateForm(formElement) {
  const inputs = formElement.querySelectorAll(
    "input[required], textarea[required], select[required]"
  );
  let isValid = true;
  let firstInvalidField = null;

  inputs.forEach((input) => {
    const value = input.value.trim();
    const fieldName =
      input.getAttribute("name") || input.getAttribute("id") || "Field";

    // Remove previous validation classes
    input.classList.remove("is-invalid", "is-valid");

    if (!value) {
      input.classList.add("is-invalid");
      isValid = false;
      if (!firstInvalidField) {
        firstInvalidField = input;
      }

      // Add custom error message
      let errorDiv = input.parentNode.querySelector(".invalid-feedback");
      if (!errorDiv) {
        errorDiv = document.createElement("div");
        errorDiv.className = "invalid-feedback";
        input.parentNode.appendChild(errorDiv);
      }
      errorDiv.textContent = `${fieldName} is required`;
    } else {
      input.classList.add("is-valid");

      // Remove error message
      const errorDiv = input.parentNode.querySelector(".invalid-feedback");
      if (errorDiv) {
        errorDiv.remove();
      }
    }

    // Email validation
    if (input.type === "email" && value) {
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(value)) {
        input.classList.remove("is-valid");
        input.classList.add("is-invalid");
        isValid = false;

        let errorDiv = input.parentNode.querySelector(".invalid-feedback");
        if (!errorDiv) {
          errorDiv = document.createElement("div");
          errorDiv.className = "invalid-feedback";
          input.parentNode.appendChild(errorDiv);
        }
        errorDiv.textContent = "Please enter a valid email address";
      }
    }
  });

  // Focus on first invalid field
  if (firstInvalidField) {
    firstInvalidField.focus();
    firstInvalidField.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  return isValid;
}

// Enhanced Loading State Helper
function setLoadingState(element, isLoading, originalText = "") {
  if (!element) return;

  if (isLoading) {
    element.disabled = true;
    element.setAttribute("data-original-text", element.innerHTML);
    element.innerHTML = `
            <span class="spinner-border spinner-border-sm me-2" role="status">
                <span class="visually-hidden">Loading...</span>
            </span>
            Loading...
        `;
    element.classList.add("loading-state");
  } else {
    element.disabled = false;
    const originalContent =
      element.getAttribute("data-original-text") || originalText;
    element.innerHTML = originalContent;
    element.classList.remove("loading-state");
    element.removeAttribute("data-original-text");
  }
}

// Intersection Observer for Enhanced Animations
function initIntersectionObserver() {
  const observerOptions = {
    threshold: 0.1,
    rootMargin: "0px 0px -50px 0px",
  };

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add("fade-in");

        // Add staggered animation for child elements
        const children = entry.target.querySelectorAll(
          ".book-card, .feature-card"
        );
        children.forEach((child, index) => {
          setTimeout(() => {
            child.classList.add("animate-in");
          }, index * 100);
        });
      }
    });
  }, observerOptions);

  // Observe all sections and cards
  document
    .querySelectorAll(
      ".books-section, .features-section, .book-card, .feature-card"
    )
    .forEach((el) => {
      observer.observe(el);
    });
}

// Enhanced Parallax Effect
function initParallaxEffects() {
  const parallaxHandler = throttle(() => {
    const scrolled = window.pageYOffset;

    // Parallax for floating elements
    const parallaxElements = document.querySelectorAll(
      ".floating-book-icon, .deco-line, .deco-circle"
    );
    parallaxElements.forEach((element, index) => {
      const speed = (index + 1) * 0.05; // Reduced speed for smoother effect
      const yPos = scrolled * speed;
      element.style.transform += ` translateY(${yPos}px)`;
    });

    // Parallax for hero decorations
    const heroDecorations = document.querySelectorAll(
      ".hero-decorations .deco-line"
    );
    heroDecorations.forEach((line, index) => {
      const speed = 0.2 + index * 0.05;
      line.style.transform = `translateX(${scrolled * speed}px)`;
    });
  }, 16); // ~60fps

  window.addEventListener("scroll", parallaxHandler);
}

// Enhanced Mobile Menu Functionality
function initMobileMenu() {
  const navbarToggler = document.querySelector(".navbar-toggler");
  const navbarCollapse = document.querySelector(".navbar-collapse");

  if (navbarToggler && navbarCollapse) {
    navbarToggler.addEventListener("click", function () {
      navbarCollapse.classList.toggle("show");

      // Animate hamburger icon
      this.classList.toggle("active");
    });

    // Close mobile menu when clicking on a link
    document.querySelectorAll(".navbar-nav .nav-link").forEach((link) => {
      link.addEventListener("click", () => {
        navbarCollapse.classList.remove("show");
        navbarToggler.classList.remove("active");
      });
    });

    // Close mobile menu when clicking outside
    document.addEventListener("click", (e) => {
      if (
        !navbarToggler.contains(e.target) &&
        !navbarCollapse.contains(e.target)
      ) {
        navbarCollapse.classList.remove("show");
        navbarToggler.classList.remove("active");
      }
    });
  }
}

// Performance Optimization - Enhanced Lazy Loading
function initLazyLoading() {
  if ("IntersectionObserver" in window) {
    const imageObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          const img = entry.target;

          // Add loading animation
          img.classList.add("loading");

          const actualSrc = img.dataset.src || img.src;
          img.src = actualSrc;

          img.onload = () => {
            img.classList.remove("lazy", "loading");
            img.classList.add("loaded");
          };

          img.onerror = () => {
            img.classList.remove("lazy", "loading");
            img.classList.add("error");
            // Set fallback image
            img.src =
              "data:image/svg+xml;base64," +
              btoa(`
                <svg width="200" height="280" xmlns="http://www.w3.org/2000/svg">
                    <rect width="100%" height="100%" fill="#4A90E2"/>
                    <text x="50%" y="50%" text-anchor="middle" fill="white" font-size="14" font-family="Arial, sans-serif">
                        ESSSL Library
                    </text>
                </svg>
                        `);
          };

          imageObserver.unobserve(img);
        }
      });
    });

    document.querySelectorAll("img[data-src]").forEach((img) => {
      imageObserver.observe(img);
    });
  }
}

// Enhanced Scroll-to-Top Functionality
function initScrollToTop() {
  // Create scroll to top button
  const scrollBtn = document.createElement("button");
  scrollBtn.innerHTML = '<i class="fas fa-arrow-up"></i>';
  scrollBtn.className = "scroll-to-top";
  scrollBtn.setAttribute("aria-label", "Scroll to top");
  scrollBtn.style.cssText = `
        position: fixed;
        bottom: 7rem;
        right: 2rem;
        width: 50px;
        height: 50px;
        background: var(--primary-color, #4A90E2);
        color: white;
        border: none;
        border-radius: 50%;
        cursor: pointer;
        z-index: 999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(74, 144, 226, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
    `;

  document.body.appendChild(scrollBtn);

  // Show/hide button based on scroll position
  const toggleScrollBtn = throttle(() => {
    if (window.scrollY > 300) {
      scrollBtn.style.opacity = "1";
      scrollBtn.style.visibility = "visible";
    } else {
      scrollBtn.style.opacity = "0";
      scrollBtn.style.visibility = "hidden";
    }
  }, 100);

  window.addEventListener("scroll", toggleScrollBtn);

  // Smooth scroll to top
  scrollBtn.addEventListener("click", () => {
    window.scrollTo({
      top: 0,
      behavior: "smooth",
    });
  });
}

// Enhanced Error Handling
function handleError(error, context = "Application") {
  console.error(`${context} Error:`, error);

  // Show user-friendly error message
  showNotification(
    `Something went wrong in ${context}. Please try again or contact ESSSL Library support.`,
    "danger"
  );
}

// Enhanced Local Storage Management
const StorageManager = {
  prefix: "abcLibrary_",

  set: function (key, value, expiry = null) {
    const item = {
      value: value,
      timestamp: Date.now(),
      expiry: expiry,
    };
    try {
      localStorage.setItem(this.prefix + key, JSON.stringify(item));
      return true;
    } catch (error) {
      console.warn("LocalStorage is not available:", error);
      return false;
    }
  },

  get: function (key) {
    try {
      const item = localStorage.getItem(this.prefix + key);
      if (!item) return null;

      const parsed = JSON.parse(item);

      // Check if item has expired
      if (parsed.expiry && Date.now() > parsed.expiry) {
        localStorage.removeItem(this.prefix + key);
        return null;
      }

      return parsed.value;
    } catch (error) {
      console.warn("Error reading from localStorage:", error);
      return null;
    }
  },

  remove: function (key) {
    try {
      localStorage.removeItem(this.prefix + key);
      return true;
    } catch (error) {
      console.warn("Error removing from localStorage:", error);
      return false;
    }
  },

  clear: function () {
    try {
      const keys = Object.keys(localStorage);
      keys.forEach((key) => {
        if (key.startsWith(this.prefix)) {
          localStorage.removeItem(key);
        }
      });
      return true;
    } catch (error) {
      console.warn("Error clearing localStorage:", error);
      return false;
    }
  },
};

// Utility Functions
function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

function throttle(func, limit) {
  let inThrottle;
  return function () {
    const args = arguments;
    const context = this;
    if (!inThrottle) {
      func.apply(context, args);
      inThrottle = true;
      setTimeout(() => (inThrottle = false), limit);
    }
  };
}

// Animation utility functions
function fadeIn(element, duration = 300) {
  element.style.opacity = "0";
  element.style.display = "block";

  let start = null;
  function animate(timestamp) {
    if (!start) start = timestamp;
    const progress = timestamp - start;
    const opacity = Math.min(progress / duration, 1);

    element.style.opacity = opacity;

    if (progress < duration) {
      requestAnimationFrame(animate);
    }
  }

  requestAnimationFrame(animate);
}

function fadeOut(element, duration = 300) {
  let start = null;
  const initialOpacity = parseFloat(getComputedStyle(element).opacity);

  function animate(timestamp) {
    if (!start) start = timestamp;
    const progress = timestamp - start;
    const opacity = initialOpacity - (initialOpacity * progress) / duration;

    element.style.opacity = Math.max(opacity, 0);

    if (progress < duration) {
      requestAnimationFrame(animate);
    } else {
      element.style.display = "none";
    }
  }

  requestAnimationFrame(animate);
}

// Add custom CSS animations and styles
const customStyles = document.createElement("style");
customStyles.textContent = `
    /* Loading screen enhancements */
    .fade-in {
        animation: pageSlideIn 0.8s ease-out;
    }
    
    @keyframes pageSlideIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    /* Notification animations */
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
    
    @keyframes slideUp {
        from { transform: translateY(30px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes fadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }
    
    /* Book animations */
    .hover-animate:hover {
        transform: translateY(-5px) scale(1.02);
        transition: all 0.3s ease;
    }
    
    .pulse-animate {
        animation: pulse 0.8s ease-in-out;
    }
    
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }
    
    .animate-in {
        animation: slideUp 0.6s ease-out forwards;
    }
    
    /* Heart animation */
    @keyframes floatingHeart {
        0% { transform: translateY(0) scale(1); opacity: 1; }
        100% { transform: translateY(-50px) scale(1.5); opacity: 0; }
    }
    
    /* Image loading states */
    .book-card img {
        transition: opacity 0.3s ease;
    }
    
    .book-card img.loading {
        opacity: 0.7;
    }
    
    .book-card img.loaded {
        opacity: 1;
    }
    
    .book-card img.error {
        opacity: 0.8;
    }
    
    /* Loading states */
    .loading-state {
        position: relative;
        pointer-events: none;
        opacity: 0.7;
    }
    
    /* Scroll to top button */
    .scroll-to-top:hover {
        transform: translateY(-2px) scale(1.1);
        box-shadow: 0 6px 16px rgba(74, 144, 226, 0.4) !important;
    }
    
    /* Navbar enhancements */
    #mainNavbar {
        transition: all 0.3s ease;
    }
    
    #mainNavbar.scrolled {
        background: rgba(255, 255, 255, 0.95) !important;
        backdrop-filter: blur(10px);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }
    
    /* Mobile menu enhancements */
    .navbar-toggler.active span {
        background: var(--primary-color, #4A90E2);
    }
    
    /* Enhanced notifications */
    .notification-custom {
        border-left: 4px solid var(--primary-color, #4A90E2);
    }
    
    .notification-custom.alert-success {
        border-left-color: #28a745;
    }
    
    .notification-custom.alert-warning {
        border-left-color: #ffc107;
    }
    
    .notification-custom.alert-danger {
        border-left-color: #dc3545;
    }
`;

document.head.appendChild(customStyles);

// Global error handler
window.addEventListener("error", (e) => {
  handleError(e.error, "Global");
});

window.addEventListener("unhandledrejection", (e) => {
  handleError(e.reason, "Promise");
  e.preventDefault();
});

// Export functions for use in other scripts
window.ABCLibrary = {
  showNotification,
  setLoadingState,
  validateForm,
  searchBooks,
  handleError,
  StorageManager,
  fadeIn,
  fadeOut,
  debounce,
  throttle,
  version: "2.0.0",
};

console.log("ESSSL Library Enhanced JavaScript v2.0.0 loaded successfully!");
