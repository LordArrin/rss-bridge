'use strict';

const RssBridge = (() => {
    const CONFIG = { SEARCH_DELAY: 100 };

    const Utils = {
        debounce(fn, delay) {
            let t;
            return (...args) => {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), delay);
            };
        },

        escapeHtml(s) {
            return String(s ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        extractDomain(url) {
            if (!url) return '';
            
            if (!url.match(/^https?:\/\//)) {
                url = 'https://' + url;
            }

            try {
                const parsed = new URL(url);
                let domain = parsed.hostname.toLowerCase();
                
                if (domain.startsWith('www.')) {
                    domain = domain.substring(4);
                }
                
                return domain;
            } catch (e) {
                return '';
            }
        },

        isUrl(str) {
            return /^https?:\/\/.+/.test(str) || /^[a-z0-9-]+(\.[a-z0-9-]+)+(\/.*)?$/i.test(str);
        },

        domainsMatch(domain1, domain2) {
            if (!domain1 || !domain2) return false;
            
            if (domain1 === domain2) return true;
            if (domain1.endsWith('.' + domain2)) return true;
            if (domain2.endsWith('.' + domain1)) return true;
            
            return false;
        }
    };

    const FormValidator = {
        init() {
            document.querySelectorAll('form.bridge-form').forEach(form => {
                form.setAttribute('novalidate', '');
                form.addEventListener('submit', (e) => this.handleSubmit(e, form));
            });
        },

        handleSubmit(e, form) {
            const invalidInputs = Array.from(form.querySelectorAll('input:invalid, select:invalid'));
            
            if (invalidInputs.length === 0) return;
            
            e.preventDefault();
            
            invalidInputs.sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top);
            const firstInvalid = invalidInputs[0];
            
            this.showToast(firstInvalid, form);
            
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => firstInvalid.focus(), 300);
            
            invalidInputs.forEach(input => {
                const handler = () => {
                    input.classList.remove('invalid');
                    input.removeEventListener('input', handler);
                };
                input.addEventListener('input', handler);
            });
        },

        showToast(input, form) {
            const paramsContainer = input.closest('.parameters');
            if (!paramsContainer) return;
            
            form.querySelectorAll('.inline-toast').forEach(t => t.remove());
            form.querySelectorAll('.invalid').forEach(el => el.classList.remove('invalid'));
            
            input.classList.add('invalid');
            
            const toast = document.createElement('div');
            toast.className = 'inline-toast';
            
            let message = input.validationMessage || 'Please fill in this field';
            const inputId = input.id;
            if (inputId) {
                const label = form.querySelector(`label[for="${inputId}"]`);
                if (label) {
                    label.classList.add('invalid');
                    const fieldName = label.textContent.replace(/\s*:$/, '').trim();
                    message = `${fieldName}: ${message}`;
                }
            }
            toast.textContent = message;
            
            paramsContainer.appendChild(toast);
            
            requestAnimationFrame(() => {
                const containerRect = paramsContainer.getBoundingClientRect();
                const inputRect = input.getBoundingClientRect();
                const toastRect = toast.getBoundingClientRect();
                
                const leftOffset = inputRect.left - containerRect.left;
                toast.style.left = `${Math.max(0, leftOffset)}px`;
                
                const spaceAbove = inputRect.top - containerRect.top;
                const toastHeight = toastRect.height;
                const gap = 8;
                
                if (spaceAbove > toastHeight + gap) {
                    toast.style.top = `${inputRect.top - containerRect.top - toastHeight - gap}px`;
                } else {
                    toast.style.top = `${inputRect.bottom - containerRect.top + gap}px`;
                    toast.classList.add('below');
                }
                
                const toastWidth = toastRect.width;
                if (leftOffset + toastWidth > containerRect.width) {
                    toast.style.left = `${Math.max(0, containerRect.width - toastWidth)}px`;
                }
                
                toast.classList.add('visible');
            });
            
            setTimeout(() => {
                toast.classList.remove('visible');
                setTimeout(() => toast.remove(), 500);
            }, 4000);
        }
    };

    const TooltipManager = {
        init() {
            document.querySelectorAll('.info[data-title], .info[title]').forEach(el => {
                if (el.getAttribute('title') && !el.getAttribute('data-title')) {
                    el.setAttribute('data-title', el.getAttribute('title'));
                    el.removeAttribute('title');
                }
                
                el.addEventListener('mouseenter', (e) => this.show(e.target));
                el.addEventListener('mouseleave', (e) => this.hide(e.target));
            });
        },

        show(infoEl) {
            const tooltip = infoEl.querySelector('.tooltip') || this.createTooltip(infoEl);
            const card = infoEl.closest('.bridge-card');
            if (!card) return;

            tooltip.classList.remove('tooltip-top', 'tooltip-bottom', 'tooltip-left', 'tooltip-right');
            tooltip.style.left = '';
            tooltip.style.right = '';
            tooltip.style.transform = '';
            tooltip.classList.add('visible');
            
            const infoRect = infoEl.getBoundingClientRect();
            const cardRect = card.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();

            const relTop = infoRect.top - cardRect.top;
            const relLeft = infoEl.offsetLeft;
            const cardHeight = cardRect.height;
            const cardWidth = cardRect.width;

            if (relTop < cardHeight / 3) {
                tooltip.classList.add('tooltip-bottom');
            } else {
                tooltip.classList.add('tooltip-top');
            }

            const tooltipWidth = tooltipRect.width;
            const iconCenterX = relLeft + infoEl.offsetWidth / 2;
            
            if (iconCenterX - tooltipWidth / 2 < 0) {
                tooltip.classList.add('tooltip-left');
            } else if (iconCenterX + tooltipWidth / 2 > cardWidth) {
                tooltip.classList.add('tooltip-right');
            }
        },

        hide(infoEl) {
            const tooltip = infoEl.querySelector('.tooltip');
            if (tooltip) tooltip.classList.remove('visible');
        },

        createTooltip(infoEl) {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = infoEl.getAttribute('data-title');
            infoEl.appendChild(tooltip);
            return tooltip;
        }
    };

    const Search = {
        perform() {
            const f = document.getElementById('searchfield');
            const term = f?.value.trim() ?? '';
            const termLower = term.toLowerCase();
            sessionStorage.setItem('rssbridge_search_query', term);
            
            const clearBtn = document.querySelector('.search-clear-btn');
            if (clearBtn) {
                if (term.length > 0) {
                    clearBtn.classList.add('visible');
                } else {
                    clearBtn.classList.remove('visible');
                }
            }

            const isUrlSearch = Utils.isUrl(term);
            const searchDomain = isUrlSearch ? Utils.extractDomain(term) : '';
            
            let matchCount = 0;
            let firstMatchCard = null;

            document.querySelectorAll('section.bridge-card').forEach(card => {
                if (!term) { 
                    card.style.display = ''; 
                    return; 
                }

                const name = card.getAttribute('data-ref')?.toLowerCase() ?? '';
                const shortName = card.getAttribute('data-short-name')?.toLowerCase() ?? '';
                const desc = card.querySelector('.description')?.textContent.toLowerCase() ?? '';
                const domain = card.getAttribute('data-domain')?.toLowerCase() ?? '';

                let isVisible = false;

                if (!isUrlSearch) {
                    isVisible = (name + shortName + desc).includes(termLower);
                }
                
                if (isUrlSearch && searchDomain) {
                    isVisible = Utils.domainsMatch(searchDomain, domain);
                }

                if (isVisible) {
                    card.style.display = '';
                    matchCount++;
                    if (!firstMatchCard) {
                        firstMatchCard = card;
                    }
                } else {
                    card.style.display = 'none';
                }
            });

            if (isUrlSearch && matchCount === 1 && firstMatchCard) {
                const showMoreLabel = firstMatchCard.querySelector('label.showmore');
                if (showMoreLabel) {
                    const checkbox = firstMatchCard.querySelector('.showmore-box');
                    if (!checkbox.checked) {
                        showMoreLabel.click();
                    }
                }
                
                setTimeout(() => {
                    firstMatchCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstMatchCard.classList.add('highlight-pulse');
                    setTimeout(() => firstMatchCard.classList.remove('highlight-pulse'), 2000);
                }, 300);
            }
        },

        init() {
            const f = document.getElementById('searchfield');
            if (f) {
                const wrapper = document.createElement('div');
                wrapper.className = 'search-input-wrapper';
                f.parentNode.insertBefore(wrapper, f);
                wrapper.appendChild(f);
                
                const clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'search-clear-btn';
                clearBtn.setAttribute('aria-label', 'Clear search');
                clearBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>`;
                wrapper.appendChild(clearBtn);
                
                clearBtn.addEventListener('click', () => {
                    f.value = '';
                    f.dispatchEvent(new Event('input'));
                    f.focus();
                });

                const savedQuery = sessionStorage.getItem('rssbridge_search_query');
                if (savedQuery) {
                    f.value = savedQuery;
                }
                
                this.perform();
                
                f.addEventListener('input', Utils.debounce(() => this.perform(), CONFIG.SEARCH_DELAY));
            }
        }
    };

    const CardManager = {
        handleChange(e) {
            const cb = e.target;
            if (!cb.classList.contains('showmore-box') || !cb.checked) return;
            document.querySelectorAll('.showmore-box').forEach(o => {
                if (o !== cb) o.checked = false;
            });
        },

        openFromHash() {
            const hash = window.location.hash.slice(1);
            if (!hash) return;
            const bridge = document.getElementById(hash);
            const showMoreLabel = bridge?.querySelector('label.showmore');
            if (showMoreLabel) {
                showMoreLabel.click();
                setTimeout(() => bridge.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
            }
        },

        init() {
            document.addEventListener('change', e => this.handleChange(e));
            this.openFromHash();
            window.addEventListener('hashchange', () => this.openFromHash());
        }
    };

    const PlaceholderHelper = {
        init() {
            document.addEventListener('contextmenu', e => {
                if (!e.target.classList.contains('info')) return;
                e.preventDefault();
                const input = document.getElementById(e.target.getAttribute('data-for'));
                if (input) {
                    input.value = input.getAttribute('placeholder') ?? '';
                    input.focus();
                }
            });
        }
    };

    const FavoritesManager = {
        STORAGE_KEY: 'rssbridge_favorites',
        STAR_ICON: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>',
        originalOrder: [],
        cardsCache: [],
        isInitialized: false,
        isMobile: window.matchMedia('(max-width: 767px)').matches,
        lastToggleTime: 0,
        
        getFavorites() {
            try {
                const stored = localStorage.getItem(this.STORAGE_KEY);
                return stored ? JSON.parse(stored) : [];
            } catch (e) {
                return [];
            }
        },
        
        saveFavorites(favorites) {
            try {
                localStorage.setItem(this.STORAGE_KEY, JSON.stringify(favorites));
            } catch (e) {
                console.error('Failed to save favorites:', e);
            }
        },
        
        toggleFavorite(bridgeName) {
            // Throttle
            const now = Date.now();
            if (now - this.lastToggleTime < 200) return false;
            this.lastToggleTime = now;

            const favorites = this.getFavorites();
            const index = favorites.indexOf(bridgeName);
            
            if (index === -1) {
                favorites.push(bridgeName);
            } else {
                favorites.splice(index, 1);
            }
            
            this.saveFavorites(favorites);
            return index === -1;
        },
        
        sortBridges(useAnimation = false) {
            const favorites = this.getFavorites();
            const cards = this.cardsCache;
            
            // Skip FLIP on mobile for performance
            const shouldAnimate = useAnimation && !this.isMobile;
            
            // Phase 1: Read all positions (batch reads to avoid layout thrashing)
            let firstPositions = null;
            if (shouldAnimate) {
                firstPositions = new Map();
                for (const card of cards) {
                    firstPositions.set(card, card.getBoundingClientRect());
                }
            }
            
            // Phase 2: Remove favorite class from all cards
            for (const card of cards) {
                card.classList.remove('favorite');
            }
            
            // Phase 3: Split and sort
            const favoriteCards = [];
            const regularCards = [];
            
            for (const card of cards) {
                const bridgeName = card.id.replace('bridge-', '');
                if (favorites.includes(bridgeName)) {
                    favoriteCards.push(card);
                } else {
                    regularCards.push(card);
                }
            }
            
            favoriteCards.sort((a, b) => {
                return favorites.indexOf(a.id.replace('bridge-', '')) 
                     - favorites.indexOf(b.id.replace('bridge-', ''));
            });
            
            regularCards.sort((a, b) => {
                return this.originalOrder.indexOf(a.id) - this.originalOrder.indexOf(b.id);
            });
            
            // Phase 4: Apply CSS Grid order (no DOM manipulation!)
            let orderIndex = 1;
            for (const card of favoriteCards) {
                card.classList.add('favorite');
                card.style.order = orderIndex++;
            }
            for (const card of regularCards) {
                card.style.order = orderIndex++;
            }
            
            // Phase 5: Animate only cards that actually moved
            if (shouldAnimate && firstPositions) {
                // Read new positions in one batch
                const movedCards = [];
                for (const card of cards) {
                    const first = firstPositions.get(card);
                    const last = card.getBoundingClientRect();
                    const deltaX = first.left - last.left;
                    const deltaY = first.top - last.top;
                    
                    if (Math.abs(deltaX) > 1 || Math.abs(deltaY) > 1) {
                        movedCards.push({ card, deltaX, deltaY });
                    }
                }
                
                // Animate only moved cards in next frame
                requestAnimationFrame(() => {
                    this.applyFlipAnimation(movedCards);
                });
            }
        },
        
        applyFlipAnimation(movedCards) {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }

            for (const { card, deltaX, deltaY } of movedCards) {
                card.classList.add('sorted');
                
                // Enable GPU compositing
                card.style.willChange = 'transform';
                card.style.transform = `translate(${deltaX}px, ${deltaY}px) translateZ(0)`;
                card.style.transition = 'none';
            }
            
            // Force single reflow for all cards at once
            if (movedCards.length > 0) {
                void movedCards[0].card.offsetWidth;
            }
            
            // Play animation
            for (const { card } of movedCards) {
                card.style.transition = 'transform 0.55s cubic-bezier(0.2, 0, 0, 1)';
                card.style.transform = 'translateZ(0)';
                
                const cleanup = (e) => {
                    if (e && e.propertyName !== 'transform') return;
                    card.style.transition = '';
                    card.style.transform = '';
                    card.style.willChange = '';
                    card.removeEventListener('transitionend', cleanup);
                };
                card.addEventListener('transitionend', cleanup);
                
                setTimeout(() => cleanup(), 700);
            }
        },
        
        updateButtons() {
            const favorites = this.getFavorites();
            
            for (const card of this.cardsCache) {
                const btn = card.querySelector('.favorite-btn');
                if (!btn) continue;
                
                const bridgeName = btn.getAttribute('data-bridge');
                const isFav = favorites.includes(bridgeName);
                
                if (isFav) {
                    btn.classList.add('active');
                    btn.setAttribute('aria-label', 'Remove from favorites');
                    btn.setAttribute('title', 'Remove from favorites');
                } else {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-label', 'Add to favorites');
                    btn.setAttribute('title', 'Add to favorites');
                }
            }
        },
        
        init() {
            // Cache DOM references once
            this.cardsCache = Array.from(document.querySelectorAll('section.bridge-card'));
            this.originalOrder = this.cardsCache.map(card => card.id);
            
            // Set initial order values for CSS Grid
            this.cardsCache.forEach((card, index) => {
                card.style.order = index + 1;
            });
            
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('.favorite-btn');
                if (!btn) return;
                
                e.preventDefault();
                e.stopPropagation();
                
                const bridgeName = btn.getAttribute('data-bridge');
                const isAdded = this.toggleFavorite(bridgeName);
                
                this.updateButtons();
                this.sortBridges(true);
                this.showToast(isAdded ? 'Added to favorites' : 'Removed from favorites');
            });
            
            if (this.isMobile) {
                document.addEventListener('touchend', (e) => {
                    const btn = e.target.closest('.favorite-btn');
                    if (!btn) return;
                    
                    btn.classList.add('touch-active');
                    setTimeout(() => btn.classList.remove('touch-active'), 150);
                }, { passive: true });
            }
            
            this.sortBridges(false);
            this.updateButtons();
            
            document.documentElement.classList.add('favorites-loaded');
            this.isInitialized = true;
        },
        
        showToast(message) {
            document.querySelectorAll('.favorite-toast').forEach(t => t.remove());
            
            const toast = document.createElement('div');
            toast.className = 'favorite-toast';
            toast.innerHTML = this.STAR_ICON + `<span>${Utils.escapeHtml(message)}</span>`;
            document.body.appendChild(toast);
            
            requestAnimationFrame(() => {
                toast.classList.add('visible');
            });
            
            setTimeout(() => {
                toast.classList.remove('visible');
                setTimeout(() => toast.remove(), 500);
            }, 2000);
        }
    };

    return {
        _init() {
            Search.init();
            CardManager.init();
            PlaceholderHelper.init();
            TooltipManager.init();
            FormValidator.init();
            FavoritesManager.init();
        }
    };
})();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        RssBridge._init();
        setTimeout(() => Search.perform(), 100);
    });
} else {
    RssBridge._init();
}
