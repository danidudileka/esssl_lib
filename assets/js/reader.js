// Enhanced Reader Theme JavaScript with Library Interactions
document.addEventListener('DOMContentLoaded', function() {
    initFormValidation();
    initLoginForm();
    initLibraryAnimations();
    initBookInteractions();
    initResponsiveHandling();
    
    console.log('✅ Enhanced ABC Library Reader Theme loaded successfully!');
});

// Initialize library-specific animations
function initLibraryAnimations() {
    // Stagger book animations on load
    const books = document.querySelectorAll('.book');
    books.forEach((book, index) => {
        book.style.opacity = '0';
        book.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            book.style.transition = 'all 0.6s ease-out';
            book.style.opacity = '1';
            book.style.transform = 'translateY(0)';
        }, 100 * index);
    });
    
    // Character reading animation enhancement
    const character = document.querySelector('.reading-character');
    if (character) {
        setInterval(() => {
            character.style.transform = 'translateX(-50%) scale(1.02)';
            setTimeout(() => {
                character.style.transform = 'translateX(-50%) scale(1)';
            }, 200);
        }, 4000);
    }
    
    // Knowledge bubbles floating effect
    const bubbles = document.querySelectorAll('.knowledge-bubble');
    bubbles.forEach((bubble, index) => {
        bubble.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.3)';
            this.style.background = 'rgba(255, 255, 255, 0.3)';
            this.style.boxShadow = '0 8px 25px rgba(255, 255, 255, 0.2)';
        });
        
        bubble.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
            this.style.background = 'rgba(255, 255, 255, 0.15)';
            this.style.boxShadow = 'none';
        });
        
        // Add click effect
        bubble.addEventListener('click', function() {
            createFloatingEffect(this);
        });
    });
    
    // Library building glow effect
    const building = document.querySelector('.library-building');
    if (building) {
        setInterval(() => {
            building.style.filter = 'brightness(1.1)';
            setTimeout(() => {
                building.style.filter = 'brightness(1)';
            }, 1000);
        }, 6000);
    }
}

// Initialize book interactions
function initBookInteractions() {
    const books = document.querySelectorAll('.book');
    
    books.forEach((book, index) => {
        // Enhanced hover effect
        book.addEventListener('mouseenter', function() {
            this.style.zIndex = '100';
            this.style.filter = 'brightness(1.2)';
            showBookTooltip(this);
        });
        
        book.addEventListener('mouseleave', function() {
            this.style.zIndex = '';
            this.style.filter = 'brightness(1)';
            hideBookTooltip(this);
        });
        
        // Click effect with sound simulation
        book.addEventListener('click', function(e) {
            e.preventDefault();
            createBookSparkle(this);
            createRippleEffect(this, e);
            simulatePageTurn();
        });
    });
}

// Show enhanced book tooltip
function showBookTooltip(book) {
    const existingTooltip = document.querySelector('.book-tooltip');
    if (existingTooltip) {
        existingTooltip.remove();
    }
    
    const tooltip = document.createElement('div');
    tooltip.className = 'book-tooltip';
    tooltip.textContent = book.getAttribute('data-title');
    tooltip.style.cssText = `
        position: absolute;
        bottom: 50px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(0, 0, 0, 0.9);
        color: white;
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 11px;
        white-space: nowrap;
        z-index: 1000;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s ease;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    `;
    
    book.appendChild(tooltip);
    
    setTimeout(() => {
        tooltip.style.opacity = '1';
    }, 50);
}

// Hide book tooltip
function hideBookTooltip(book) {
    const tooltip = book.querySelector('.book-tooltip');
    if (tooltip) {
        tooltip.style.opacity = '0';
        setTimeout(() => {
            tooltip.remove();
        }, 300);
    }
}

// Create sparkle effect when book is clicked
function createBookSparkle(book) {
    for (let i = 0; i < 3; i++) {
        setTimeout(() => {
            const sparkle = document.createElement('div');
            sparkle.className = 'book-sparkle';
            sparkle.style.cssText = `
                position: absolute;
                top: ${-10 + (Math.random() * 20)}px;
                left: ${Math.random() * 100}%;
                transform: translateX(-50%);
                width: ${15 + Math.random() * 10}px;
                height: ${15 + Math.random() * 10}px;
                background: radial-gradient(circle, #ffd700, #ffed4a, transparent);
                border-radius: 50%;
                pointer-events: none;
                animation: sparkle-effect 0.8s ease-out forwards;
                z-index: 1000;
            `;
            
            book.appendChild(sparkle);
            
            setTimeout(() => {
                sparkle.remove();
            }, 800);
        }, i * 100);
    }
}

// Create ripple effect
function createRippleEffect(element, event) {
    const rect = element.getBoundingClientRect();
    const ripple = document.createElement('div');
    ripple.className = 'ripple-effect';
    
    const size = Math.max(rect.width, rect.height);
    ripple.style.cssText = `
        position: absolute;
        top: 50%;
        left: 50%;
        width: ${size}px;
        height: ${size}px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.6), transparent);
        border-radius: 50%;
        transform: translate(-50%, -50%) scale(0);
        animation: ripple 0.6s ease-out;
        pointer-events: none;
        z-index: 999;
    `;
    
    element.appendChild(ripple);
    
    setTimeout(() => {
        ripple.remove();
    }, 600);
}

// Simulate page turn effect
function simulatePageTurn() {
    const openBook = document.querySelector('.open-book');
    if (openBook) {
        openBook.style.transform = 'rotateY(5deg)';
        setTimeout(() => {
            openBook.style.transform = 'rotateY(0deg)';
        }, 300);
    }
}

// Create floating effect for bubbles
function createFloatingEffect(bubble) {
    const icon = bubble.querySelector('i');
    if (icon) {
        icon.style.animation = 'none';
        icon.style.transform = 'scale(1.5) rotateY(360deg)';
        
        setTimeout(() => {
            icon.style.animation = '';
            icon.style.transform = '';
        }, 600);
    }
}

// Initialize responsive handling
function initResponsiveHandling() {
    let resizeTimer;
    
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            adjustForMobile();
            repositionElements();
        }, 250);
    });
    
    // Initial adjustment
    adjustForMobile();
}

// Adjust for mobile devices
function adjustForMobile() {
    const isMobile = window.innerWidth <= 768;
    const libraryScene = document.querySelector('.library-scene');
    
    if (isMobile && libraryScene) {
        libraryScene.classList.add('mobile-optimized');
        
        // Reduce animation intensity on mobile
        const books = document.querySelectorAll('.book');
        books.forEach(book => {
            book.style.animationDuration = '6s'; // Slower animations
        });
        
    } else if (libraryScene) {
        libraryScene.classList.remove('mobile-optimized');
        
        // Restore normal animations
        const books = document.querySelectorAll('.book');
        books.forEach(book => {
            book.style.animationDuration = '4s';
        });
    }
}

// Reposition elements for better responsive behavior
function repositionElements() {
    const welcomeMessage = document.querySelector('.welcome-message');
    const building = document.querySelector('.library-building');
    const bookshelf = document.querySelector('.animated-bookshelf');
    
    if (window.innerWidth <= 480) {
        if (welcomeMessage) {
            welcomeMessage.style.fontSize = '0.9rem';
        }
        if (building) {
            building.style.transform = 'translateX(-50%) scale(0.7)';
        }
        if (bookshelf) {
            bookshelf.style.transform = 'translateX(-50%) scale(0.8)';
        }
    }
}

// Form validation (keeping existing code)
function initFormValidation() {
    const form = document.getElementById('loginForm');
    if (!form) return;
    
    const inputs = form.querySelectorAll('input[required]');
    
    inputs.forEach(input => {
        input.addEventListener('blur', () => validateField(input));
        input.addEventListener('input', () => clearValidation(input));
    });
    
    form.addEventListener('submit', function(e) {
        let isValid = true;
        
        inputs.forEach(input => {
            if (!validateField(input)) {
                isValid = false;
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            showError('Please check your input fields');
        }
    });
}

// Validate field
function validateField(input) {
    const value = input.value.trim();
    
    if (!value) {
        input.style.borderColor = '#ef4444';
        input.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
        return false;
    }
    
    if (input.name === 'username_email' && value.includes('@')) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
            input.style.borderColor = '#ef4444';
            input.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
            return false;
        }
    }
    
    if (input.name === 'password' && value.length < 6) {
        input.style.borderColor = '#ef4444';
        input.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
        return false;
    }
    
    input.style.borderColor = '#10b981';
    input.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.1)';
    return true;
}

// Clear validation
function clearValidation(input) {
    input.style.borderColor = '';
    input.style.boxShadow = '';
}

// Initialize login form
function initLoginForm() {
    const form = document.getElementById('loginForm');
    const signinBtn = document.getElementById('signinBtn');
    
    if (form && signinBtn) {
        form.addEventListener('submit', function() {
            const btnText = signinBtn.querySelector('.btn-text');
            const btnLoading = signinBtn.querySelector('.btn-loading');
            
            if (btnText && btnLoading) {
                btnText.classList.add('d-none');
                btnLoading.classList.remove('d-none');
                signinBtn.disabled = true;
                
                // Add a slight animation to the button
                signinBtn.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    signinBtn.style.transform = 'scale(1)';
                }, 100);
            }
        });
    }
}

// Password toggle
function togglePassword() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');
    
    if (passwordInput && toggleIcon) {
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
            
            // Add subtle animation
            toggleIcon.style.transform = 'scale(1.1)';
            setTimeout(() => {
                toggleIcon.style.transform = 'scale(1)';
            }, 150);
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
            
            // Add subtle animation
            toggleIcon.style.transform = 'scale(1.1)';
            setTimeout(() => {
                toggleIcon.style.transform = 'scale(1)';
            }, 150);
        }
    }
}

// Google notice
function showGoogleNotice() {
    showNotification('Google Sign-In integration coming soon! Please use regular login for now.', 'info');
}

// Show error
function showError(message) {
    showNotification(message, 'error');
}

// Enhanced notification system
function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.custom-notification');
    existingNotifications.forEach(notification => notification.remove());
    
    const notification = document.createElement('div');
    notification.className = `custom-notification alert alert-${type === 'error' ? 'danger' : 'success'} position-fixed`;
    notification.style.cssText = `
        top: 2rem; 
        right: 2rem; 
        z-index: 9999; 
        min-width: 320px;
        max-width: 400px;
        animation: slideInRight 0.4s ease-out;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        border: none;
        border-radius: 8px;
    `;
    
    notification.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
            <span class="flex-grow-1">${message}</span>
            <button type="button" class="ms-2 btn-close-custom" onclick="this.parentElement.parentElement.remove()" 
                    style="background:none;border:none;color:inherit;font-size:1.2rem;padding:0;cursor:pointer;">&times;</button>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.animation = 'slideOutRight 0.4s ease-in';
            setTimeout(() => {
                notification.remove();
            }, 400);
        }
    }, 5000);
}

// Add enhanced CSS animations
const enhancedStyle = document.createElement('style');
enhancedStyle.textContent = `
    @keyframes sparkle-effect {
        0% {
            transform: translateX(-50%) scale(0) rotate(0deg);
            opacity: 1;
        }
        50% {
            transform: translateX(-50%) scale(1.5) rotate(180deg);
            opacity: 0.8;
        }
        100% {
            transform: translateX(-50%) scale(0) rotate(360deg);
            opacity: 0;
        }
    }
    
    @keyframes ripple {
        0% {
            transform: translate(-50%, -50%) scale(0);
            opacity: 1;
        }
        100% {
            transform: translate(-50%, -50%) scale(2);
            opacity: 0;
        }
    }
    
    @keyframes slideInRight {
        from { 
            transform: translateX(100%); 
            opacity: 0; 
        }
        to { 
            transform: translateX(0); 
            opacity: 1; 
        }
    }
    
    @keyframes slideOutRight {
        from { 
            transform: translateX(0); 
            opacity: 1; 
        }
        to { 
            transform: translateX(100%); 
            opacity: 0; 
        }
    }
    
    .mobile-optimized {
        animation-duration: 0.5s !important;
    }
    
    .mobile-optimized * {
        animation-duration: 0.5s !important;
    }
    
    .btn-close-custom:hover {
        background: rgba(255, 255, 255, 0.2) !important;
        border-radius: 50%;
        padding: 2px 6px !important;
    }
`;
document.head.appendChild(enhancedStyle);

// Performance monitoring
function logPerformance() {
    if (window.performance && window.performance.timing) {
        const timing = window.performance.timing;
        const loadTime = timing.loadEventEnd - timing.navigationStart;
        console.log(`🚀 Page loaded in ${loadTime}ms`);
    }
}

// Call performance logging after load
window.addEventListener('load', logPerformance);
