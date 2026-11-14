<?php
/**
 * Offline Synchronization Control Center
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$pdo = getDatabaseConnection();

$syncLogs = $pdo->query("
    SELECT sq.*, u.full_name as user_name
    FROM `offline_sync_queue` sq
    JOIN `users` u ON sq.user_id = u.id
    ORDER BY sq.id DESC
    LIMIT 50
")->fetchAll();

$pageTitle = 'Offline Synchronization Center';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Offline Synchronization Center</h4>
        <small class="text-muted">Field Sales & Delivery Background Queue Status</small>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary btn-touch shadow-sm" onclick="DMSOffline.syncAll();">
            <i class="bi bi-arrow-repeat me-1"></i> Flush & Sync Local Queue Now
        </button>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Local Device Status Card -->
    <div class="col-12 col-md-5">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-phone me-2 text-primary"></i>This Device Local Queue</h6>
            </div>
            <div class="card-body p-3">
                <div class="p-3 bg-light rounded-3 mb-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Device Network State:</span>
                        <span id="sync-page-net-pill" class="sync-indicator-banner sync-online"><i class="bi bi-wifi"></i> Online</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Queued Offline Transactions:</span>
                        <span class="fw-bold fs-5 text-primary" id="sync-page-queue-count">0</span>
                    </div>
                </div>

                <p class="small text-muted mb-3">
                    <strong>How Offline Mode Works:</strong> When field staff (Order Bookers & Delivery Drivers) are in basements or rural areas without mobile signal, orders, shop registrations, and payment collections are stored safely in the device's local offline database. As soon as connectivity returns, they are dispatched and booked automatically.
                </p>

                <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="if(confirm('Clear local offline queue? (Unsynced transactions will be lost)')){ localStorage.removeItem('dms_offline_queue'); DMSOffline.renderSyncUI(); updatePageQueue(); }">
                    <i class="bi bi-trash me-1"></i> Reset Local Device Queue
                </button>
            </div>
        </div>
    </div>

    <!-- Server Sync Processing Logs -->
    <div class="col-12 col-md-7">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-server me-2 text-primary"></i>Server Sync History</h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($syncLogs)): ?>
                    <div class="p-4 text-center text-muted">No offline sync events processed yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-responsive-card align-middle mb-0">
                            <thead class="table-light small">
                                <tr>
                                    <th>Sync UUID</th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Processed Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($syncLogs as $sl): ?>
                                    <tr>
                                        <td data-label="UUID" class="font-monospace small"><?= htmlspecialchars(substr($sl['sync_uuid'], 0, 18)) ?>...</td>
                                        <td data-label="User"><?= htmlspecialchars($sl['user_name']) ?></td>
                                        <td data-label="Type"><span class="badge bg-light text-dark border"><?= htmlspecialchars($sl['entity_type']) ?></span></td>
                                        <td data-label="Date"><?= formatDateTime($sl['processed_at'] ?: $sl['created_at']) ?></td>
                                        <td data-label="Status">
                                            <span class="badge <?= $sl['status'] === 'processed' ? 'bg-success' : 'bg-danger' ?>">
                                                <?= htmlspecialchars($sl['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function updatePageQueue() {
    const qCount = DMSOffline.getQueueCount();
    const cntElem = document.getElementById('sync-page-queue-count');
    if (cntElem) cntElem.innerText = qCount;

    const netElem = document.getElementById('sync-page-net-pill');
    if (netElem) {
        if (navigator.onLine) {
            netElem.className = 'sync-indicator-banner sync-online';
            netElem.innerHTML = '<i class="bi bi-wifi"></i> Online';
        } else {
            netElem.className = 'sync-indicator-banner sync-offline';
            netElem.innerHTML = '<i class="bi bi-wifi-off"></i> Offline Mode';
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    updatePageQueue();
    window.addEventListener('online', updatePageQueue);
    window.addEventListener('offline', updatePageQueue);
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
