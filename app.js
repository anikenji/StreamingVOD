/**
 * StreamingVOD Portfolio - React-like JavaScript Application
 * Features: Scroll animations, smooth navigation, component updates
 */

// ============================================
// App State & Configuration
// ============================================
const App = {
    isInitialized: false,
    scrollPosition: 0,
    observers: [],

    // Configuration
    config: {
        animationThreshold: 0.15,
        scrollOffset: 80,
        mobileBreakpoint: 768
    }
};

// ============================================
// Utility Functions
// ============================================
const Utils = {
    // Debounce function for performance
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    // Throttle function
    throttle(func, limit) {
        let inThrottle;
        return function (...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },

    // Check if element is in viewport
    isInViewport(element, threshold = 0) {
        const rect = element.getBoundingClientRect();
        return (
            rect.top <= (window.innerHeight || document.documentElement.clientHeight) * (1 - threshold) &&
            rect.bottom >= 0
        );
    },

    // Smooth scroll to element
    scrollToElement(selector, offset = 80) {
        const element = document.querySelector(selector);
        if (element) {
            const top = element.getBoundingClientRect().top + window.pageYOffset - offset;
            window.scrollTo({
                top,
                behavior: 'smooth'
            });
        }
    }
};

// ============================================
// Navigation Component
// ============================================
const Navigation = {
    navbar: null,
    mobileMenuBtn: null,
    navLinks: null,
    lastScrollY: 0,

    init() {
        this.navbar = document.querySelector('.navbar');
        this.mobileMenuBtn = document.querySelector('.mobile-menu-btn');
        this.navLinks = document.querySelector('.nav-links');

        this.bindEvents();
        this.handleScroll();
    },

    bindEvents() {
        // Mobile menu toggle
        if (this.mobileMenuBtn) {
            this.mobileMenuBtn.addEventListener('click', () => this.toggleMobileMenu());
        }

        // Smooth scroll for nav links
        document.querySelectorAll('.nav-links a[href^="#"]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const targetId = link.getAttribute('href');
                Utils.scrollToElement(targetId, App.config.scrollOffset);
                this.closeMobileMenu();
            });
        });

        // Scroll handler
        window.addEventListener('scroll', Utils.throttle(() => this.handleScroll(), 100));
    },

    handleScroll() {
        const currentScrollY = window.pageYOffset;

        // Update navbar background
        if (this.navbar) {
            if (currentScrollY > 50) {
                this.navbar.classList.add('scrolled');
            } else {
                this.navbar.classList.remove('scrolled');
            }
        }

        // Update active nav link
        this.updateActiveLink();

        this.lastScrollY = currentScrollY;
    },

    updateActiveLink() {
        const sections = document.querySelectorAll('section[id]');
        const scrollPosition = window.pageYOffset + 100;

        sections.forEach(section => {
            const sectionTop = section.offsetTop;
            const sectionHeight = section.offsetHeight;
            const sectionId = section.getAttribute('id');

            if (scrollPosition >= sectionTop && scrollPosition < sectionTop + sectionHeight) {
                document.querySelectorAll('.nav-links a').forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === `#${sectionId}`) {
                        link.classList.add('active');
                    }
                });
            }
        });
    },

    toggleMobileMenu() {
        if (this.navLinks) {
            this.navLinks.classList.toggle('active');
            this.mobileMenuBtn.classList.toggle('active');
        }
    },

    closeMobileMenu() {
        if (this.navLinks) {
            this.navLinks.classList.remove('active');
            this.mobileMenuBtn.classList.remove('active');
        }
    }
};

// ============================================
// Scroll Animations Component
// ============================================
const ScrollAnimations = {
    observer: null,

    init() {
        this.createObserver();
        this.observeElements();
    },

    createObserver() {
        const options = {
            root: null,
            rootMargin: '0px 0px -50px 0px',
            threshold: App.config.animationThreshold
        };

        this.observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animated');
                    // Optionally unobserve after animation
                    // this.observer.unobserve(entry.target);
                }
            });
        }, options);
    },

    observeElements() {
        const animatedElements = document.querySelectorAll('[data-animate]');
        animatedElements.forEach(element => {
            this.observer.observe(element);
        });
    },

    // Manually trigger animations for elements in viewport on load
    triggerInitialAnimations() {
        const animatedElements = document.querySelectorAll('[data-animate]');
        animatedElements.forEach(element => {
            if (Utils.isInViewport(element, 0.1)) {
                element.classList.add('animated');
            }
        });
    }
};

// ============================================
// Hero Component
// ============================================
const Hero = {
    heroSection: null,
    heroContent: null,

    init() {
        this.heroSection = document.querySelector('.hero');
        this.heroContent = document.querySelector('.hero-content');

        this.setupParallax();
        this.animateStats();
    },

    setupParallax() {
        if (!this.heroSection) return;

        window.addEventListener('scroll', Utils.throttle(() => {
            const scrolled = window.pageYOffset;
            const heroHeight = this.heroSection.offsetHeight;

            if (scrolled < heroHeight) {
                const parallaxValue = scrolled * 0.3;
                const opacityValue = 1 - (scrolled / heroHeight) * 0.5;

                if (this.heroContent) {
                    this.heroContent.style.transform = `translateY(${parallaxValue}px)`;
                    this.heroContent.style.opacity = Math.max(opacityValue, 0.3);
                }
            }
        }, 16));
    },

    animateStats() {
        const stats = document.querySelectorAll('.stat-value');
        stats.forEach(stat => {
            stat.classList.add('animated');
        });
    }
};

// ============================================
// Feature Cards Component
// ============================================
const FeatureCards = {
    cards: [],

    init() {
        this.cards = document.querySelectorAll('.feature-card');
        this.setupHoverEffects();
    },

    setupHoverEffects() {
        this.cards.forEach(card => {
            card.addEventListener('mousemove', (e) => this.handleMouseMove(e, card));
            card.addEventListener('mouseleave', (e) => this.handleMouseLeave(e, card));
        });
    },

    handleMouseMove(e, card) {
        const rect = card.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        card.style.setProperty('--mouse-x', `${x}px`);
        card.style.setProperty('--mouse-y', `${y}px`);
    },

    handleMouseLeave(e, card) {
        card.style.removeProperty('--mouse-x');
        card.style.removeProperty('--mouse-y');
    }
};

// ============================================
// Player Cards Component
// ============================================
const PlayerCards = {
    init() {
        const playerCards = document.querySelectorAll('.player-card');

        playerCards.forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.style.transform = 'translateY(-10px) scale(1.02)';
            });

            card.addEventListener('mouseleave', () => {
                card.style.transform = 'translateY(0) scale(1)';
            });
        });
    }
};

// ============================================
// Smooth Scroll Component
// ============================================
const SmoothScroll = {
    init() {
        // Handle all anchor links with hash
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', (e) => {
                const href = anchor.getAttribute('href');
                if (href !== '#') {
                    e.preventDefault();
                    Utils.scrollToElement(href, App.config.scrollOffset);
                }
            });
        });

        // Scroll to top functionality
        this.setupScrollToTop();
    },

    setupScrollToTop() {
        const scrollTopBtn = document.createElement('button');
        scrollTopBtn.className = 'scroll-to-top';
        scrollTopBtn.innerHTML = '↑';
        scrollTopBtn.setAttribute('aria-label', 'Scroll to top');
        document.body.appendChild(scrollTopBtn);

        // Add styles dynamically
        const style = document.createElement('style');
        style.textContent = `
            .scroll-to-top {
                position: fixed;
                bottom: 30px;
                right: 30px;
                width: 50px;
                height: 50px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border: none;
                border-radius: 50%;
                color: white;
                font-size: 1.5rem;
                cursor: pointer;
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
                z-index: 999;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .scroll-to-top:hover {
                transform: translateY(-5px);
                box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
            }
            .scroll-to-top.visible {
                opacity: 1;
                visibility: visible;
            }
        `;
        document.head.appendChild(style);

        // Toggle visibility
        window.addEventListener('scroll', Utils.throttle(() => {
            if (window.pageYOffset > 500) {
                scrollTopBtn.classList.add('visible');
            } else {
                scrollTopBtn.classList.remove('visible');
            }
        }, 100));

        // Click handler
        scrollTopBtn.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
};

// ============================================
// Changelog Timeline Component
// ============================================
const ChangelogTimeline = {
    init() {
        const timelineItems = document.querySelectorAll('.changelog-item');

        timelineItems.forEach((item, index) => {
            item.style.transitionDelay = `${index * 0.1}s`;
        });
    }
};

// ============================================
// Tech Stack Animation
// ============================================
const TechStack = {
    init() {
        const techItems = document.querySelectorAll('.tech-item');

        techItems.forEach((item, index) => {
            item.style.transitionDelay = `${index * 0.08}s`;
        });
    }
};

// ============================================
// Loading Animation
// ============================================
const Loading = {
    init() {
        // Add loading class to body
        document.body.classList.add('loading');

        // Remove loading state after content loads
        window.addEventListener('load', () => {
            setTimeout(() => {
                document.body.classList.remove('loading');
                document.body.classList.add('loaded');
            }, 300);
        });
    }
};

// ============================================
// Cursor Effects (Optional - for desktop)
// ============================================
const CursorEffects = {
    cursor: null,

    init() {
        // Only enable on desktop
        if (window.innerWidth < 768) return;

        this.createCursor();
        this.bindEvents();
    },

    createCursor() {
        this.cursor = document.createElement('div');
        this.cursor.className = 'custom-cursor';
        document.body.appendChild(this.cursor);

        const style = document.createElement('style');
        style.textContent = `
            .custom-cursor {
                width: 20px;
                height: 20px;
                border: 2px solid rgba(102, 126, 234, 0.5);
                border-radius: 50%;
                position: fixed;
                pointer-events: none;
                z-index: 9999;
                transition: transform 0.1s ease, border-color 0.2s ease;
                transform: translate(-50%, -50%);
            }
            .custom-cursor.hover {
                transform: translate(-50%, -50%) scale(1.5);
                border-color: rgba(102, 126, 234, 1);
            }
        `;
        document.head.appendChild(style);
    },

    bindEvents() {
        document.addEventListener('mousemove', (e) => {
            this.cursor.style.left = e.clientX + 'px';
            this.cursor.style.top = e.clientY + 'px';
        });

        // Add hover effect for interactive elements
        const interactiveElements = document.querySelectorAll('a, button, .feature-card, .player-card, .tech-item');
        interactiveElements.forEach(el => {
            el.addEventListener('mouseenter', () => this.cursor.classList.add('hover'));
            el.addEventListener('mouseleave', () => this.cursor.classList.remove('hover'));
        });
    }
};

// ============================================
// Initialize Application
// ============================================
const initApp = () => {
    if (App.isInitialized) return;

    // Initialize all components
    Loading.init();
    Navigation.init();
    ScrollAnimations.init();
    Hero.init();
    FeatureCards.init();
    PlayerCards.init();
    SmoothScroll.init();
    ChangelogTimeline.init();
    TechStack.init();

    // Optional: Custom cursor for desktop
    // CursorEffects.init();

    // Trigger initial animations for elements already in viewport
    setTimeout(() => {
        ScrollAnimations.triggerInitialAnimations();
    }, 100);

    App.isInitialized = true;
    console.log('🎬 StreamingVOD Portfolio initialized successfully!');
};

// ============================================
// DOM Ready Handler
// ============================================
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApp);
} else {
    initApp();
}

// ============================================
// Export for potential module usage
// ============================================
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { App, Utils, Navigation, ScrollAnimations, Hero };
}
