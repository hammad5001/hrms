/**
 * Balitech Employee Policy & Handbook Interactive Engine
 * Featuring Live Search, Category Filtering, Accordions & Dark/Light Theme Switching
 */

(function () {
    'use strict';

    function initPolicyEngine() {
        const searchInput = document.getElementById('policySearchInput');
        const clearBtn = document.getElementById('btnPolicyClearSearch');
        const expandAllBtn = document.getElementById('btnPolicyExpandAll');
        const collapseAllBtn = document.getElementById('btnPolicyCollapseAll');
        const catPills = document.querySelectorAll('.policy-cat-pill');
        const policyCards = document.querySelectorAll('.policy-card');
        const tocLinks = document.querySelectorAll('.policy-toc-link');
        const emptyState = document.getElementById('policyEmptySearch');

        // Theme Toggle Setup
        setupThemeToggle();

        if (!policyCards.length) return;

        let activeCategory = 'all';
        let searchQuery = '';

        // Card Accordion Click Handling
        policyCards.forEach(card => {
            const header = card.querySelector('.policy-card-header');
            if (header) {
                // Avoid multiple event listeners
                if (header.dataset.hasAccordionListener) return;
                header.dataset.hasAccordionListener = 'true';

                header.addEventListener('click', (e) => {
                    if (e.target.closest('button, a')) return;
                    card.classList.toggle('collapsed');
                });
            }
        });

        // Filter & Search Logic
        function applyFilters() {
            let visibleCount = 0;
            const query = searchQuery.trim().toLowerCase();

            policyCards.forEach(card => {
                const cardCat = card.getAttribute('data-category') || '';
                const cardText = card.textContent.toLowerCase();
                const cardNum = card.getAttribute('data-policy-num') || '';

                const matchesCat = (activeCategory === 'all' || cardCat.includes(activeCategory));
                const matchesSearch = !query || cardText.includes(query) || cardNum === query;

                if (matchesCat && matchesSearch) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Update TOC links visibility
            tocLinks.forEach(link => {
                const targetId = link.getAttribute('href')?.replace('#', '');
                const targetCard = document.getElementById(targetId);
                if (targetCard) {
                    link.style.display = targetCard.style.display === 'none' ? 'none' : '';
                }
            });

            if (emptyState) {
                if (visibleCount === 0) {
                    emptyState.classList.add('show');
                } else {
                    emptyState.classList.remove('show');
                }
            }
        }

        // Search Input Event
        if (searchInput && !searchInput.dataset.hasListener) {
            searchInput.dataset.hasListener = 'true';
            searchInput.addEventListener('input', (e) => {
                searchQuery = e.target.value;
                applyFilters();
            });
        }

        // Clear Search
        if (clearBtn && !clearBtn.dataset.hasListener) {
            clearBtn.dataset.hasListener = 'true';
            clearBtn.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.value = '';
                    searchQuery = '';
                    applyFilters();
                    searchInput.focus();
                }
            });
        }

        // Expand All
        if (expandAllBtn && !expandAllBtn.dataset.hasListener) {
            expandAllBtn.dataset.hasListener = 'true';
            expandAllBtn.addEventListener('click', () => {
                policyCards.forEach(card => card.classList.remove('collapsed'));
            });
        }

        // Collapse All
        if (collapseAllBtn && !collapseAllBtn.dataset.hasListener) {
            collapseAllBtn.dataset.hasListener = 'true';
            collapseAllBtn.addEventListener('click', () => {
                policyCards.forEach(card => card.classList.add('collapsed'));
            });
        }

        // Category Pills Click
        catPills.forEach(pill => {
            if (pill.dataset.hasListener) return;
            pill.dataset.hasListener = 'true';

            pill.addEventListener('click', () => {
                catPills.forEach(p => p.classList.remove('active'));
                pill.classList.add('active');
                activeCategory = pill.getAttribute('data-cat') || 'all';
                applyFilters();
            });
        });

        // Scroll & TOC link click smooth handling
        tocLinks.forEach(link => {
            if (link.dataset.hasListener) return;
            link.dataset.hasListener = 'true';

            link.addEventListener('click', (e) => {
                e.preventDefault();
                const targetId = link.getAttribute('href')?.replace('#', '');
                const targetEl = document.getElementById(targetId);
                if (targetEl) {
                    targetEl.classList.remove('collapsed');
                    targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    tocLinks.forEach(l => l.classList.remove('active'));
                    link.classList.add('active');
                }
            });
        });

        // Intersection Observer for Active TOC Link Highlight
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const id = entry.target.getAttribute('id');
                        tocLinks.forEach(l => {
                            if (l.getAttribute('href') === `#${id}`) {
                                l.classList.add('active');
                            } else {
                                l.classList.remove('active');
                            }
                        });
                    }
                });
            }, {
                rootMargin: '-20% 0px -70% 0px'
            });

            policyCards.forEach(card => observer.observe(card));
        }
    }

    // Dual Theme System
    function setupThemeToggle() {
        const savedTheme = localStorage.getItem('balitech_policy_theme') || 'dark';
        applyTheme(savedTheme);

        document.querySelectorAll('.policy-theme-toggle-btn').forEach(btn => {
            if (btn.dataset.hasListener) return;
            btn.dataset.hasListener = 'true';

            btn.addEventListener('click', () => {
                const currentTheme = document.body.getAttribute('data-policy-theme') || 'dark';
                const newTheme = (currentTheme === 'dark') ? 'light' : 'dark';
                applyTheme(newTheme);
            });
        });
    }

    function applyTheme(theme) {
        document.body.setAttribute('data-policy-theme', theme);
        localStorage.setItem('balitech_policy_theme', theme);

        document.querySelectorAll('.policy-theme-toggle-btn').forEach(btn => {
            if (theme === 'dark') {
                btn.innerHTML = '<i class="fas fa-sun" style="color: #f59e0b;"></i> <span>Light Mode</span>';
                btn.setAttribute('title', 'Switch to Light Mode');
            } else {
                btn.innerHTML = '<i class="fas fa-moon" style="color: #6366f1;"></i> <span>Dark Mode</span>';
                btn.setAttribute('title', 'Switch to Dark Mode');
            }
        });
    }

    // Auto init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPolicyEngine);
    } else {
        initPolicyEngine();
    }

    window.initPolicyEngine = initPolicyEngine;
    window.applyPolicyTheme = applyTheme;
})();
