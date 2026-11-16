/**
 * Real-Time Field Activity & Live Counters Poller
 * Enterprise Distribution Management System (DMS)
 * Updates sidebar badge counts, blinking section alerts, and in-page data without reload.
 */

(function () {
    'use strict';

    let lastStateHash = null;
    let lastActivityId = null;
    let isInitialLoad = true;
    let isFetching = false;
    let pollIntervalMs = 4000;
    let pollTimer = null;

    // Detect base URL from current script or window location
    const getBaseUrl = function () {
        const path = window.location.pathname;
        const dmsIdx = path.indexOf('/DMS_2.0');
        if (dmsIdx !== -1) {
            return path.substring(0, dmsIdx + 8);
        }
        return '';
    };

    const BASE_URL = getBaseUrl();

    // Subtle audio chime via Web Audio API (Zero external audio file needed)
    function playSubtleChime() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();
            const now = ctx.currentTime;
            
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, now); // D5
            osc.frequency.exponentialRampToValueAtTime(880.00, now + 0.12); // A5
            
            gain.gain.setValueAtTime(0.04, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
            
            osc.connect(gain);
            gain.connect(ctx.destination);
            
            osc.start(now);
            osc.stop(now + 0.35);
        } catch (e) {
            // Audio context not allowed before user gesture
        }
    }

    // Display Floating Activity Toast Notification
    function showFieldActivityToast(activity) {
        if (!activity) return;

        let toastContainer = document.getElementById('dmsLiveToastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'dmsLiveToastContainer';
            toastContainer.style.position = 'fixed';
            toastContainer.style.bottom = '24px';
            toastContainer.style.right = '24px';
            toastContainer.style.zIndex = '9999';
            toastContainer.style.maxWidth = '380px';
            document.body.appendChild(toastContainer);
        }

        const iconClass = activity.act_type === 'order' ? 'bi-cart-check-fill text-primary' :
                          (activity.act_type === 'delivery' ? 'bi-truck text-success' :
                          (activity.act_type === 'payment' ? 'bi-cash-coin text-warning' :
                          (activity.act_type === 'return' ? 'bi-box-arrow-in-left text-danger' : 'bi-wallet2 text-info')));

        const toastEl = document.createElement('div');
        toastEl.className = 'toast show dms-live-toast shadow-lg border-0 mb-2 rounded-3 bg-white';
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        toastEl.innerHTML = `
            <div class="toast-header bg-white border-bottom-0 pt-2 pb-1 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <span class="live-pulse-dot me-2"></span>
                    <i class="bi ${iconClass} me-2 fs-6"></i>
                    <strong class="me-auto text-dark small" style="font-size: 0.8rem;">Field Activity Just Now</strong>
                </div>
                <button type="button" class="btn-close" aria-label="Close"></button>
            </div>
            <div class="toast-body pt-1 pb-3 px-3">
                <div class="fw-bold text-dark small mb-1">${escapeHtml(activity.title)}</div>
                <div class="text-muted small" style="font-size: 0.78rem;">${escapeHtml(activity.description)}</div>
            </div>
        `;

        toastEl.querySelector('.btn-close').addEventListener('click', () => toastEl.remove());
        toastContainer.appendChild(toastEl);
        playSubtleChime();

        // Auto remove toast after 6 seconds
        setTimeout(() => {
            toastEl.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            toastEl.style.opacity = '0';
            toastEl.style.transform = 'translateY(15px)';
            setTimeout(() => toastEl.remove(), 400);
        }, 6000);
    }

    // Helper: Escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Update Individual Navigation Item Badge
    function updateBadge(badgeId, count, pulse = true) {
        const badge = document.getElementById(badgeId);
        if (!badge) return;

        badge.textContent = count;
        if (count > 0) {
            badge.classList.remove('d-none');
            if (pulse) badge.classList.add('pulse-badge');
        } else {
            badge.classList.add('d-none');
            badge.classList.remove('pulse-badge');
        }
    }

    // Update Section Header Blinking & Badge
    function updateSectionHeader(headerId, badgeId, count, badgeLabel = '') {
        const header = document.getElementById(headerId);
        const badge = document.getElementById(badgeId);

        if (header) {
            if (count > 0) {
                header.classList.add('section-blink-alert');
            } else {
                header.classList.remove('section-blink-alert');
            }
        }

        if (badge) {
            badge.textContent = badgeLabel ? `${count} ${badgeLabel}` : count;
            if (count > 0) {
                badge.classList.remove('d-none');
                badge.classList.add('pulse-badge');
            } else {
                badge.classList.add('d-none');
                badge.classList.remove('pulse-badge');
            }
        }
    }

    // Floating Notice when Data Silently Refreshed
    function showLiveUpdatedNotice() {
        let ind = document.getElementById('dmsLiveUpdatedIndicator');
        if (!ind) {
            ind = document.createElement('div');
            ind.id = 'dmsLiveUpdatedIndicator';
            ind.className = 'dms-live-indicator';
            ind.innerHTML = `
                <div class="badge bg-success text-white shadow-sm px-3 py-1 rounded-pill d-flex align-items-center gap-1">
                    <span class="live-pulse-dot" style="background-color: #ffffff;"></span>
                    <span>Live data updated just now</span>
                </div>
            `;
            document.body.appendChild(ind);
        }

        ind.classList.add('show');
        setTimeout(() => ind.classList.remove('show'), 2500);
    }

    // In-Page Content Silent Swapper (Without Full Page Reload)
    function refreshCurrentPageData() {
        // Skip refreshing if user is actively filling out a form
        const activeEl = document.activeElement;
        const isUserInteracting = activeEl && (
            activeEl.tagName === 'INPUT' || 
            activeEl.tagName === 'TEXTAREA' || 
            (activeEl.tagName === 'SELECT' && activeEl.matches(':focus'))
        );

        if (isUserInteracting) {
            return;
        }

        const currentUrl = window.location.href;

        fetch(currentUrl, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            let updated = false;

            // 1. Delivery Driver App: Cards container & KPIs
            const oldDeliveryContainer = document.getElementById('deliveryCardsContainer');
            const newDeliveryContainer = doc.getElementById('deliveryCardsContainer');
            if (oldDeliveryContainer && newDeliveryContainer) {
                oldDeliveryContainer.innerHTML = newDeliveryContainer.innerHTML;
                
                // Update KPI text numbers
                ['kpiPendingCount', 'kpiCompletedCount', 'visibleStopsCount', 'totalStopsCount', 'badgeAll', 'badgePending', 'badgeCompleted'].forEach(id => {
                    const oldEl = document.getElementById(id);
                    const newEl = doc.getElementById(id);
                    if (oldEl && newEl) oldEl.textContent = newEl.textContent;
                });

                // Re-run instant client-side filter if search input has active text
                if (typeof window.applyDeliveryFilters === 'function') {
                    window.applyDeliveryFilters();
                }
                updated = true;
            }

            // 2. Generic Card / Main Container Swap for List Pages
            const targets = [
                '#liveDataContainer',
                'main.dms-main-content .table-responsive',
                '#dashboardStatsGrid'
            ];

            for (const selector of targets) {
                const oldEl = document.querySelector(selector);
                const newEl = doc.querySelector(selector);
                if (oldEl && newEl && !updated) {
                    oldEl.innerHTML = newEl.innerHTML;
                    updated = true;
                    break;
                }
            }

            if (updated) {
                showLiveUpdatedNotice();
            }
        })
        .catch(err => {
            console.warn('Silent live refresh skipped:', err);
        });
    }

    // Master Poll Routine
    function pollLiveUpdates() {
        if (isFetching) return;
        isFetching = true;

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3500);

        fetch(BASE_URL + '/ajax/live_updates.php', {
            signal: controller.signal,
            headers: { 'Cache-Control': 'no-cache' }
        })
        .then(response => {
            clearTimeout(timeoutId);
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(data => {
            isFetching = false;
            if (!data || !data.success || !data.logged_in) {
                return;
            }

            // 1. Update Inner Navigation Item Badges
            updateBadge('badgePendingOrders', data.counts.pending_orders);
            updateBadge('badgePendingDeliveries', data.counts.pending_deliveries);
            updateBadge('badgeReadyDispatch', data.counts.ready_dispatch);
            updateBadge('badgeStockHandover', data.counts.quarantine_resell);
            updateBadge('badgeCashHandover', data.counts.pending_handovers);

            // 2. Update Section Headers & Blinking Alert Animations
            // Section 1: Field Sales & Distribution
            updateSectionHeader('secSalesHeader', 'secSalesBadge', data.section_counts.secSales);

            // Section 2: Warehouse & Inventory (Blinks when orders are approved & ready for dispatch)
            const whLabel = data.counts.ready_dispatch > 0 ? 'Ready' : '';
            const whCount = data.counts.ready_dispatch > 0 ? data.counts.ready_dispatch : data.section_counts.secWarehouse;
            updateSectionHeader('secWarehouseHeader', 'secWarehouseBadge', whCount, whLabel);

            // Section 4: Financials & Accounts
            updateSectionHeader('secFinanceHeader', 'secFinanceBadge', data.section_counts.secFinance);

            // 3. Detect New Field Activity & State Changes
            if (isInitialLoad) {
                lastStateHash = data.hash;
                lastActivityId = data.latest_activity ? data.latest_activity.act_id : null;
                isInitialLoad = false;
            } else {
                const hasStateChanged = (data.hash !== lastStateHash);
                const isNewActivity = (data.latest_activity && data.latest_activity.act_id !== lastActivityId);

                if (hasStateChanged || isNewActivity) {
                    lastStateHash = data.hash;

                    // If a new field activity occurred, trigger toast
                    if (isNewActivity) {
                        lastActivityId = data.latest_activity.act_id;
                        showFieldActivityToast(data.latest_activity);
                    }

                    // Dispatch custom DOM event for active page scripts
                    window.dispatchEvent(new CustomEvent('dms:field-activity', { detail: data }));

                    // Refresh active page data in background without reload
                    refreshCurrentPageData();
                }
            }
        })
        .catch(err => {
            clearTimeout(timeoutId);
            isFetching = false;
            // Silent error on background poll failure
        });
    }

    // Expose global manual refresh function
    window.refreshCurrentPageLive = refreshCurrentPageData;

    // Start background poller on DOM ready
    document.addEventListener('DOMContentLoaded', function () {
        // Initial run
        pollLiveUpdates();

        // Recurring poll
        pollTimer = setInterval(pollLiveUpdates, pollIntervalMs);

        // Adjust polling when tab visibility changes (conserve resources)
        document.addEventListener('visibilitychange', function () {
            clearInterval(pollTimer);
            if (!document.hidden) {
                pollIntervalMs = 4000;
                pollLiveUpdates();
            } else {
                pollIntervalMs = 15000;
            }
            pollTimer = setInterval(pollLiveUpdates, pollIntervalMs);
        });
    });

})();
