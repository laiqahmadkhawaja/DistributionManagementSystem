/**
 * Enterprise Distribution Management System (DMS)
 * Offline-First & Background Sync Engine for Field Reps & Delivery Staff
 */

const DMSOffline = {
    STORAGE_KEY: 'dms_offline_queue',
    CACHE_PRODUCTS_KEY: 'dms_cached_products',
    CACHE_CUSTOMERS_KEY: 'dms_cached_customers',

    init: function () {
        this.updateConnectionStatus();
        window.addEventListener('online', () => this.handleNetworkChange(true));
        window.addEventListener('offline', () => this.handleNetworkChange(false));

        // Periodic auto-sync every 30 seconds if online
        setInterval(() => {
            if (navigator.onLine && this.getQueueCount() > 0) {
                this.syncAll();
            }
        }, 30000);

        this.renderSyncUI();
    },

    getQueue: function () {
        try {
            const data = localStorage.getItem(this.STORAGE_KEY);
            return data ? JSON.parse(data) : [];
        } catch (e) {
            return [];
        }
    },

    getQueueCount: function () {
        return this.getQueue().length;
    },

    addToQueue: function (entityType, payload) {
        const queue = this.getQueue();
        const item = {
            sync_uuid: 'SYNC-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9),
            entity_type: entityType,
            payload: payload,
            created_at: new Date().toISOString()
        };
        queue.push(item);
        localStorage.setItem(this.STORAGE_KEY, JSON.stringify(queue));
        this.renderSyncUI();
        if (window.showDmsToast) {
            window.showDmsToast(`Saved offline (${entityType}). Will auto-sync when connected.`, 'info');
        }
        return item.sync_uuid;
    },

    syncAll: async function () {
        const queue = this.getQueue();
        if (queue.length === 0) {
            if (window.showDmsToast) window.showDmsToast('All records already synced!', 'success');
            this.renderSyncUI();
            return;
        }

        const syncBtn = document.getElementById('dms-sync-btn');
        if (syncBtn) {
            syncBtn.disabled = true;
            syncBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Syncing...';
        }

        let syncedCount = 0;
        const remainingQueue = [];

        for (const item of queue) {
            try {
                const syncUrl = (window.DMS_BASE_URL || '') + '/ajax/sync.php';
                const response = await fetch(syncUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(item)
                });

                const result = await response.json();
                if (result.success) {
                    syncedCount++;
                } else {
                    console.error('Sync item failed:', result.error);
                    remainingQueue.push(item);
                }
            } catch (err) {
                console.error('Network sync error:', err);
                remainingQueue.push(item);
                break; // Stop if network drops during sync loop
            }
        }

        localStorage.setItem(this.STORAGE_KEY, JSON.stringify(remainingQueue));
        this.renderSyncUI();

        if (syncBtn) {
            syncBtn.disabled = false;
            syncBtn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Sync';
        }

        if (syncedCount > 0 && window.showDmsToast) {
            window.showDmsToast(`Successfully synced ${syncedCount} offline record(s)!`, 'success');
        }
    },

    handleNetworkChange: function (isOnline) {
        this.updateConnectionStatus();
        if (isOnline) {
            if (window.showDmsToast) window.showDmsToast('Internet restored. Syncing offline data...', 'success');
            this.syncAll();
        } else {
            if (window.showDmsToast) window.showDmsToast('You are offline. Orders and collections will be stored locally.', 'danger');
        }
    },

    updateConnectionStatus: function () {
        const isOnline = navigator.onLine;
        const banner = document.getElementById('dms-network-pill');
        if (banner) {
            if (isOnline) {
                banner.className = 'sync-indicator-banner sync-online';
                banner.innerHTML = '<i class="bi bi-wifi"></i> Online';
            } else {
                banner.className = 'sync-indicator-banner sync-offline';
                banner.innerHTML = '<i class="bi bi-wifi-off"></i> Offline Mode';
            }
        }
    },

    renderSyncUI: function () {
        this.updateConnectionStatus();
        const count = this.getQueueCount();
        const badge = document.getElementById('dms-sync-badge');
        if (badge) {
            if (count > 0) {
                badge.innerText = count;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        }
    }
};

document.addEventListener('DOMContentLoaded', function () {
    DMSOffline.init();
    const syncBtn = document.getElementById('dms-sync-btn');
    if (syncBtn) {
        syncBtn.addEventListener('click', function (e) {
            e.preventDefault();
            DMSOffline.syncAll();
        });
    }
});
