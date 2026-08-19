(function () {
    'use strict';

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var fixedHeader = document.querySelector('.fixed_header');
    var parallaxSections = [];
    var parallaxElements = [];
    var scrollUpdateScheduled = false;

    function selectAll(selector, context) {
        return Array.prototype.slice.call((context || document).querySelectorAll(selector));
    }

    function finishPageLoad() {
        var loader = document.querySelector('[data-loader="circle-side"]');
        var preloader = document.getElementById('preloader');
        var hero = document.querySelector('.animate_hero');

        if (loader) {
            loader.hidden = true;
        }

        if (preloader) {
            preloader.classList.add('loaded');
        }

        if (hero) {
            hero.classList.add('is-transitioned');
        }
    }

    function initializeDataStyles() {
        selectAll('.opacity-mask').forEach(function (element) {
            element.style.backgroundColor = element.dataset.opacityMask || '';
        });

        selectAll('.background-image').forEach(function (element) {
            element.style.backgroundImage = element.dataset.background || '';
        });
    }

    function revealElement(element, cue, delay) {
        if (reducedMotion) {
            element.style.animation = 'none';
            element.style.opacity = '1';
            element.style.transform = 'none';
            return;
        }

        element.style.animationName = cue;
        element.style.animationDuration = '600ms';
        element.style.animationDelay = delay + 'ms';
        element.style.animationTimingFunction = 'ease';
        element.style.animationFillMode = 'both';
    }

    function initializeRevealAnimations() {
        var revealTargets = [];

        selectAll('[data-cue]').forEach(function (element) {
            revealTargets.push({
                observedElement: element,
                reveal: function () {
                    revealElement(element, element.dataset.cue, Number(element.dataset.delay) || 0);
                }
            });
        });

        selectAll('[data-cues]').forEach(function (group) {
            revealTargets.push({
                observedElement: group,
                reveal: function () {
                    var cue = group.dataset.cues;
                    var baseDelay = Number(group.dataset.delay) || 0;

                    Array.prototype.slice.call(group.children).forEach(function (element, index) {
                        revealElement(element, cue, baseDelay + (index * 100));
                    });
                }
            });
        });

        if (reducedMotion || !('IntersectionObserver' in window)) {
            revealTargets.forEach(function (target) {
                target.reveal();
            });
            return;
        }

        var revealMap = new Map();
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }

                var reveal = revealMap.get(entry.target);
                if (reveal) {
                    reveal();
                    revealMap.delete(entry.target);
                }
                observer.unobserve(entry.target);
            });
        }, {
            rootMargin: '0px 0px -15% 0px',
            threshold: 0.01
        });

        revealTargets.forEach(function (target) {
            revealMap.set(target.observedElement, target.reveal);
            observer.observe(target.observedElement);
        });
    }

    function initializePinnedImages() {
        var sections = selectAll('.pinned-image');

        if (!sections.length) {
            return;
        }

        if (reducedMotion || !('IntersectionObserver' in window)) {
            sections.forEach(function (section) {
                section.classList.add('is-visible');
            });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.2 });

        sections.forEach(function (section) {
            observer.observe(section);
        });
    }

    function initializeParallax() {
        if (reducedMotion) {
            return;
        }

        parallaxSections = selectAll('[data-jarallax]').map(function (section) {
            return {
                section: section,
                image: section.querySelector('.jarallax-img'),
                speed: Number(section.dataset.speed) || 0.2
            };
        }).filter(function (item) {
            return item.image;
        });

        parallaxElements = selectAll('[data-jarallax-element]').map(function (element) {
            return {
                element: element,
                distance: Number(element.dataset.jarallaxElement) || 0
            };
        });
    }

    function setMenuState(open) {
        var menu = document.querySelector('.main-menu');
        var layer = document.querySelector('.layer');
        var opener = document.querySelector('.hamburger_2.open_close_menu');

        if (!menu) {
            return;
        }

        menu.classList.toggle('show', open);
        document.body.classList.toggle('menu-open', open);

        if (layer) {
            layer.classList.toggle('layer-is-visible', open);
        }

        if (opener) {
            opener.setAttribute('aria-expanded', String(open));
        }
    }

    function initializeMobileMenu() {
        var menu = document.querySelector('.main-menu');
        var layer = document.querySelector('.layer');
        var opener = document.querySelector('.hamburger_2.open_close_menu');
        var closeButton = document.querySelector('.closebt.open_close_menu');

        if (!menu) {
            return;
        }

        if (opener) {
            opener.setAttribute('role', 'button');
            opener.setAttribute('tabindex', '0');
            opener.setAttribute('aria-label', 'Menü öffnen');
            opener.setAttribute('aria-controls', 'mainNav');
            opener.setAttribute('aria-expanded', 'false');

            opener.addEventListener('click', function () {
                setMenuState(!menu.classList.contains('show'));
            });

            opener.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    setMenuState(!menu.classList.contains('show'));
                }
            });
        }

        if (closeButton) {
            closeButton.setAttribute('aria-label', 'Menü schließen');
            closeButton.addEventListener('click', function (event) {
                event.preventDefault();
                setMenuState(false);
            });
        }

        if (layer) {
            layer.addEventListener('click', function () {
                setMenuState(false);
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menu.classList.contains('show')) {
                setMenuState(false);
                if (opener) {
                    opener.focus();
                }
            }
        });
    }

    function initializeFaq() {
        var faq = document.getElementById('faq');

        if (!faq) {
            return;
        }

        var triggers = selectAll('[data-bs-toggle="collapse"]', faq);

        triggers.forEach(function (trigger) {
            var targetId = (trigger.getAttribute('href') || '').replace(/^#/, '');
            var target = document.getElementById(targetId);

            if (!target) {
                return;
            }

            trigger.setAttribute('aria-controls', targetId);

            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                var shouldOpen = !target.classList.contains('show');

                triggers.forEach(function (item) {
                    var itemId = (item.getAttribute('href') || '').replace(/^#/, '');
                    var itemTarget = document.getElementById(itemId);

                    item.classList.add('collapsed');
                    item.setAttribute('aria-expanded', 'false');
                    if (itemTarget) {
                        itemTarget.classList.remove('show');
                    }
                });

                if (shouldOpen) {
                    trigger.classList.remove('collapsed');
                    trigger.setAttribute('aria-expanded', 'true');
                    target.classList.add('show');
                }
            });
        });
    }

    function updateFooterReveal() {
        var footer = document.querySelector('footer.revealed');

        if (!footer || window.innerWidth < 1024) {
            document.body.classList.remove('footer-reveal-enabled');
            document.documentElement.style.removeProperty('--footer-height');
            return;
        }

        document.documentElement.style.setProperty('--footer-height', footer.offsetHeight + 'px');
        document.body.classList.add('footer-reveal-enabled');
    }

    function updateScrollState() {
        var viewportHeight = window.innerHeight;

        if (fixedHeader) {
            fixedHeader.classList.toggle('sticky', window.scrollY > 1);
        }

        parallaxSections.forEach(function (item) {
            var rect = item.section.getBoundingClientRect();

            if (rect.bottom < 0 || rect.top > viewportHeight) {
                return;
            }

            var maximumOffset = item.section.offsetHeight * 0.1;
            var offset = Math.max(-maximumOffset, Math.min(maximumOffset, -rect.top * item.speed));
            item.image.style.transform = 'translate3d(0, ' + offset + 'px, 0) scale(1.02)';
        });

        parallaxElements.forEach(function (item) {
            var rect = item.element.getBoundingClientRect();

            if (rect.bottom < 0 || rect.top > viewportHeight) {
                return;
            }

            var progress = ((viewportHeight - rect.top) / (viewportHeight + rect.height)) - 0.5;
            item.element.style.transform = 'translate3d(0, ' + (progress * item.distance * 2) + 'px, 0)';
        });

        scrollUpdateScheduled = false;
    }

    function scheduleScrollUpdate() {
        if (scrollUpdateScheduled) {
            return;
        }

        scrollUpdateScheduled = true;
        window.requestAnimationFrame(updateScrollState);
    }

    function initialize() {
        initializeDataStyles();
        initializeRevealAnimations();
        initializePinnedImages();
        initializeParallax();
        initializeMobileMenu();
        initializeFaq();
        updateFooterReveal();
        updateScrollState();

        window.addEventListener('scroll', scheduleScrollUpdate, { passive: true });
        window.addEventListener('resize', function () {
            updateFooterReveal();
            scheduleScrollUpdate();
        });
    }

    if (document.readyState === 'complete') {
        finishPageLoad();
    } else {
        window.addEventListener('load', finishPageLoad, { once: true });
    }

    initialize();
}());
