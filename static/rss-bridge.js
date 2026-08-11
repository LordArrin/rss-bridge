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
            document.querySelectorAll('.info[title]').forEach(el => {
                el.setAttribute('data-title', el.getAttribute('title'));
                el.removeAttribute('title');
                
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
            const relLeft = infoRect.left - cardRect.left;
            const cardHeight = cardRect.height;
            const cardWidth = cardRect.width;

            if (relTop < cardHeight / 3) {
                tooltip.classList.add('tooltip-bottom');
            } else {
                tooltip.classList.add('tooltip-top');
            }

            const tooltipWidth = tooltipRect.width;
            const iconCenterX = relLeft + infoRect.width / 2;
            
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
            const term = document.getElementById('searchfield')?.value.trim().toLowerCase() ?? '';
            document.querySelectorAll('section.bridge-card').forEach(card => {
                if (!term) { card.style.display = ''; return; }

                const name = card.getAttribute('data-ref')?.toLowerCase() ?? '';
                const shortName = card.getAttribute('data-short-name')?.toLowerCase() ?? '';
                const desc = card.querySelector('.description')?.textContent.toLowerCase() ?? '';
                const url = card.querySelector('a')?.href.toLowerCase() ?? '';

                card.style.display = (name + shortName + desc + url).includes(term) ? '' : 'none';
            });
        },

        init() {
            const f = document.getElementById('searchfield');
            if (f) f.addEventListener('input', Utils.debounce(() => this.perform(), CONFIG.SEARCH_DELAY));
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
            const cb = bridge?.querySelector('.showmore-box');
            if (cb) {
                cb.checked = true;
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

    const FeedFinder = {
        async search() {
            const input = document.getElementById('searchfield');
            const out = document.getElementById('findfeedresults');
            if (!input || !out) return;

            const q = input.value.trim();
            if (!q) { out.innerHTML = ''; return; }

            out.innerHTML = '<div class="alert alert-info">Searching for matching feeds...</div>';

            try {
                const url = `${location.protocol}//${location.host}${location.pathname}?action=findfeed&format=Html&url=${encodeURIComponent(q)}`;
                const r = await fetch(url);
                
                // 404 = server explicitly says "no feed found" — valid response, not an error
                if (r.status === 404) {
                    out.innerHTML = '<div class="alert alert-warning">No Feed found! Not every bridge supports feed detection.</div>';
                    return;
                }
                
                if (!r.ok) throw new Error(`HTTP ${r.status} ${r.statusText}`);
                
                const feeds = await r.json();
                if (!Array.isArray(feeds) || !feeds.length) {
                    out.innerHTML = '<div class="alert alert-warning">No Feed found! Not every bridge supports feed detection.</div>';
                    return;
                }
                this.render(feeds, out);
            } catch (err) {
                console.error('RssBridge FeedFinder error:', err);
                out.innerHTML = `<div class="alert alert-error">Error: ${Utils.escapeHtml(err.message)}</div>`;
            }
        },

        render(feeds, out) {
            const esc = Utils.escapeHtml;
            out.innerHTML = `<h3>Found Feed(s):</h3>` + feeds.map(f => {
                const params = Object.values(f.bridgeData ?? {})
                    .map(p => `<li>${esc(p.name)}: ${esc(p.value)}</li>`).join('');
                return `
                    <div class="search-result">
                        <div class="icon"><img src="${esc(f.bridgeMeta?.icon)}" width="60" alt="" /></div>
                        <div class="content">
                            <h2><a href="${esc(f.url)}">${esc(f.bridgeMeta?.name ?? 'Unknown')}</a></h2>
                            <p><span class="description"><a href="${esc(f.url)}">${esc(f.bridgeMeta?.description ?? '')}</a></span></p>
                            <div><ul>${params}</ul></div>
                        </div>
                    </div>`;
            }).join('') + `<div class="alert alert-info">This feed may be only one of the possible feeds.</div>`;
        },

        init() {
            const btn = document.getElementById('findfeed');
            const field = document.getElementById('searchfield');
            const results = document.getElementById('findfeedresults');
            
            const clearSearch = () => {
                if (field) {
                    field.value = '';
                    field.setAttribute('autocomplete', 'off');
                }
                if (results) results.innerHTML = '';
            };
            
            clearSearch();
            requestAnimationFrame(clearSearch);
            
            window.addEventListener('pageshow', (event) => {
                if (event.persisted) clearSearch();
            });
            
            if (btn) btn.addEventListener('click', () => this.search());
            if (field) field.addEventListener('keypress', e => {
                if (e.key === 'Enter') { e.preventDefault(); this.search(); }
            });
        }
    };

    return {
        _init() {
            Search.init();
            CardManager.init();
            PlaceholderHelper.init();
            FeedFinder.init();
            TooltipManager.init();
            FormValidator.init();
        }
    };
})();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => RssBridge._init());
} else {
    RssBridge._init();
}