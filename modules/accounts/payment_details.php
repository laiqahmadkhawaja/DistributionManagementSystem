<?php
/**
 * Official Customer Payment Details Screen
 * Dedicated shareable and counter-display page for official company bank accounts & mobile wallets
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currUser = function_exists('getCurrentUser') ? getCurrentUser() : null;

// Fetch all active company accounts marked for customer display
$accounts = $pdo->query("
    SELECT * FROM `company_accounts` 
    WHERE `is_active` = 1 AND `show_on_bills` = 1 
    ORDER BY `is_primary` DESC, `display_order` ASC, `bank_name` ASC
")->fetchAll();

$bankAccounts = array_filter($accounts, fn($a) => $a['account_type'] === 'bank');
$walletAccounts = array_filter($accounts, fn($a) => in_array($a['account_type'], ['wallet', 'raast', 'other']));

$autoprint = isset($_GET['print']) && $_GET['print'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Payment Accounts - <?= htmlspecialchars($company['name'] ?? APP_NAME) ?></title>
    <link href="<?= BASE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #0f172a;
        }
        .pay-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #fff;
            padding: 24px 0;
            border-bottom: 4px solid #3b82f6;
        }
        .bank-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            position: relative;
            overflow: hidden;
        }
        .bank-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
        }
        .bank-card.is-primary {
            border: 2px solid #3b82f6;
        }
        .bank-card.is-primary::before {
            content: "★ PRIMARY RECEIVING ACCOUNT";
            position: absolute;
            top: 0;
            right: 0;
            background: #3b82f6;
            color: #ffffff;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 3px 10px;
            border-bottom-left-radius: 8px;
            letter-spacing: 0.5px;
        }
        .acct-number-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .acct-digits {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: 1px;
            color: #0f172a;
        }
        .btn-copy-tag {
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 6px;
            padding: 3px 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #fff !important;
                color: #000 !important;
            }
            .pay-header {
                background: none !important;
                color: #000 !important;
                padding: 10px 0 !important;
                border-bottom: 2px solid #000 !important;
            }
            .pay-header h3, .pay-header p, .pay-header div {
                color: #000 !important;
            }
            .bank-card {
                border: 1px solid #000 !important;
                box-shadow: none !important;
                page-break-inside: avoid;
            }
            .acct-number-box {
                border: 1px solid #000 !important;
                background: #fff !important;
            }
        }
    </style>
</head>
<body>

    <!-- Top Navigation (Hidden in Print) -->
    <div class="bg-dark text-white py-2 px-3 no-print d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <a href="<?= BASE_URL ?>/modules/accounts/index.php" class="btn btn-sm btn-outline-light text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i> Manage Accounts
            </a>
            <span class="small text-muted">| Official Distribution Payment Portal</span>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-light" onclick="window.print();">
                <i class="bi bi-printer me-1"></i> Print Counter View
            </button>
            <button type="button" class="btn btn-sm btn-success" onclick="shareOnWhatsApp();">
                <i class="bi bi-whatsapp me-1"></i> Share via WhatsApp
            </button>
        </div>
    </div>

    <!-- Header Banner -->
    <header class="pay-header">
        <div class="container text-center">
            <h3 class="fw-bold mb-1 tracking-tight"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h3>
            <p class="mb-2 text-light opacity-90 small">
                <?= htmlspecialchars($company['address'] ?? 'Central Distribution Hub, Gujranwala') ?> 
                • Phone: <?= htmlspecialchars($company['phone'] ?? '+92 300 1111111') ?>
            </p>
            <div class="badge bg-primary px-3 py-2 fs-6 rounded-pill">
                <i class="bi bi-shield-check me-1"></i> Official Verified Payment Accounts
            </div>
        </div>
    </header>

    <div class="container py-4">
        <!-- Notice Alert for Retail Customers -->
        <div class="alert alert-primary bg-white border-primary shadow-sm rounded-3 mb-4 d-flex align-items-center gap-3">
            <div class="fs-1 text-primary"><i class="bi bi-info-circle-fill"></i></div>
            <div>
                <div class="fw-bold text-dark">Please Share Deposit Receipt / Screenshot After Online Transfer</div>
                <small class="text-muted">
                    Please transfer your order invoice payments to any of our official verified bank accounts or mobile wallets below. After payment, send the transaction ID / deposit receipt screenshot to our WhatsApp <strong><?= htmlspecialchars($company['phone'] ?? '+92 300 1111111') ?></strong> for instant ledger clearance.
                </small>
            </div>
        </div>

        <!-- 1. Commercial Bank Accounts -->
        <?php if (!empty($bankAccounts)): ?>
            <div class="mb-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-bank text-primary me-2"></i>1. Commercial Bank Accounts</h5>
                    <span class="badge bg-secondary-subtle text-dark border small"><?= count($bankAccounts) ?> Channels</span>
                </div>

                <div class="row g-3">
                    <?php foreach ($bankAccounts as $acc): ?>
                        <div class="col-12 col-md-6">
                            <div class="bank-card p-3 h-100 <?= $acc['is_primary'] ? 'is-primary' : '' ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($acc['bank_name']) ?></h5>
                                        <?php if (!empty($acc['branch_name'])): ?>
                                            <small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($acc['branch_name']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 small">
                                        ONLINE BANK TRANSFER
                                    </span>
                                </div>

                                <div class="mb-2">
                                    <span class="text-muted small d-block">Account Title:</span>
                                    <strong class="fs-6 text-dark"><?= htmlspecialchars($acc['account_title']) ?></strong>
                                </div>

                                <div class="mb-2">
                                    <span class="text-muted small d-block">Account Number:</span>
                                    <div class="acct-number-box">
                                        <span class="acct-digits" id="num_<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_number']) ?></span>
                                        <button type="button" class="btn btn-outline-primary btn-copy-tag" onclick="copyValue('<?= htmlspecialchars($acc['account_number']) ?>', this)">
                                            <i class="bi bi-copy me-1"></i> Copy
                                        </button>
                                    </div>
                                </div>

                                <?php if (!empty($acc['iban'])): ?>
                                    <div class="mb-2">
                                        <span class="text-muted small d-block">IBAN Number:</span>
                                        <div class="acct-number-box">
                                            <span class="font-monospace small fw-bold text-dark text-break"><?= htmlspecialchars($acc['iban']) ?></span>
                                            <button type="button" class="btn btn-outline-secondary btn-copy-tag" onclick="copyValue('<?= htmlspecialchars($acc['iban']) ?>', this)">
                                                <i class="bi bi-copy me-1"></i> Copy
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($acc['raast_id'])): ?>
                                    <div class="p-2 bg-light rounded border mb-2 d-flex justify-content-between align-items-center">
                                        <span class="small fw-semibold text-primary"><i class="bi bi-lightning-fill text-warning me-1"></i>Raast ID: <?= htmlspecialchars($acc['raast_id']) ?></span>
                                        <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" onclick="copyValue('<?= htmlspecialchars($acc['raast_id']) ?>', this)">Copy</button>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($acc['notes'])): ?>
                                    <div class="small text-muted mt-2 border-top pt-2">
                                        <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($acc['notes']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- 2. Mobile Wallets & Raast -->
        <?php if (!empty($walletAccounts)): ?>
            <div class="mb-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-phone text-warning me-2"></i>2. Instant Mobile Wallets (JazzCash / EasyPaisa / SadaPay)</h5>
                    <span class="badge bg-secondary-subtle text-dark border small"><?= count($walletAccounts) ?> Wallets</span>
                </div>

                <div class="row g-3">
                    <?php foreach ($walletAccounts as $w): ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="bank-card p-3 h-100 <?= $w['is_primary'] ? 'is-primary' : '' ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($w['bank_name']) ?></h5>
                                    <span class="badge bg-warning-subtle text-dark border px-2 py-1 small">
                                        <?= strtoupper($w['account_type']) ?>
                                    </span>
                                </div>

                                <div class="mb-2">
                                    <span class="text-muted small d-block">Account Title:</span>
                                    <strong class="text-dark"><?= htmlspecialchars($w['account_title']) ?></strong>
                                </div>

                                <div class="mb-2">
                                    <span class="text-muted small d-block">Mobile / Till Number:</span>
                                    <div class="acct-number-box">
                                        <span class="acct-digits fs-5"><?= htmlspecialchars($w['account_number']) ?></span>
                                        <button type="button" class="btn btn-outline-warning text-dark btn-copy-tag" onclick="copyValue('<?= htmlspecialchars($w['account_number']) ?>', this)">
                                            <i class="bi bi-copy me-1"></i> Copy
                                        </button>
                                    </div>
                                </div>

                                <?php if (!empty($w['iban'])): ?>
                                    <div class="mb-2">
                                        <span class="text-muted small d-block">IBAN:</span>
                                        <div class="acct-number-box">
                                            <span class="font-monospace small fw-bold text-dark text-break"><?= htmlspecialchars($w['iban']) ?></span>
                                            <button type="button" class="btn btn-outline-secondary btn-copy-tag" onclick="copyValue('<?= htmlspecialchars($w['iban']) ?>', this)">Copy</button>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($w['notes'])): ?>
                                    <div class="small text-muted mt-2 border-top pt-2">
                                        <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($w['notes']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Footer Notice & WhatsApp Quick Contact -->
        <div class="text-center py-4 border-top mt-4">
            <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h6>
            <p class="text-muted small mb-3">
                Authorized FMCG Distribution & Supply Chain • Gujranwala • Helpline: <?= htmlspecialchars($company['phone'] ?? '+92 300 1111111') ?>
            </p>
            <div class="d-flex justify-content-center gap-2 no-print">
                <button type="button" class="btn btn-success btn-sm px-4 fw-bold" onclick="shareOnWhatsApp();">
                    <i class="bi bi-whatsapp me-1"></i> Share Details via WhatsApp
                </button>
                <button type="button" class="btn btn-outline-dark btn-sm px-3" onclick="copyAllText();">
                    <i class="bi bi-clipboard me-1"></i> Copy Text Format
                </button>
            </div>
        </div>
    </div>

    <!-- Floating Feedback Toast / Pill -->
    <div id="copyToast" class="position-fixed bottom-0 start-50 translate-middle-x mb-4 px-3 py-2 bg-dark text-white rounded-pill shadow-lg d-none" style="z-index: 9999; font-size: 0.85rem;">
        <i class="bi bi-check-circle-fill text-success me-1"></i> <span id="toastMsg">Copied to clipboard!</span>
    </div>

    <script>
    function copyValue(text, btnElem) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('Copied: ' + text);
                if (btnElem) {
                    const orig = btnElem.innerHTML;
                    btnElem.innerHTML = '<i class="bi bi-check-lg text-success"></i> Copied!';
                    setTimeout(() => { btnElem.innerHTML = orig; }, 1800);
                }
            });
        }
    }

    function showToast(msg) {
        const toast = document.getElementById('copyToast');
        const msgElem = document.getElementById('toastMsg');
        if (!toast || !msgElem) return;
        msgElem.textContent = msg;
        toast.classList.remove('d-none');
        setTimeout(() => { toast.classList.add('d-none'); }, 2000);
    }

    function generateShareableText() {
        let text = `*OFFICIAL COMPANY BANK & PAYMENT ACCOUNTS*\n` +
                   `*<?= addslashes(htmlspecialchars($company['name'] ?? APP_NAME)) ?>*\n` +
                   `Phone: <?= addslashes(htmlspecialchars($company['phone'] ?? '+92 300 1111111')) ?>\n\n` +
                   `Please transfer order invoice payments to our official accounts below:\n\n`;

        <?php foreach ($accounts as $acc): ?>
            text += `🔹 *<?= addslashes(htmlspecialchars($acc['bank_name'])) ?>*\n` +
                    `Title: <?= addslashes(htmlspecialchars($acc['account_title'])) ?>\n` +
                    `A/C #: <?= addslashes(htmlspecialchars($acc['account_number'])) ?>\n` +
                    <?php if (!empty($acc['iban'])): ?>
                    `IBAN: <?= addslashes(htmlspecialchars($acc['iban'])) ?>\n` +
                    <?php endif; ?>
                    <?php if (!empty($acc['raast_id'])): ?>
                    `Raast ID: <?= addslashes(htmlspecialchars($acc['raast_id'])) ?>\n` +
                    <?php endif; ?>
                    <?php if (!empty($acc['branch_name'])): ?>
                    `Branch: <?= addslashes(htmlspecialchars($acc['branch_name'])) ?>\n` +
                    <?php endif; ?>
                    `\n`;
        <?php endforeach; ?>

        text += `After sending payment, please share the deposit receipt or screenshot with us on this number. Thank you!`;
        return text;
    }

    function shareOnWhatsApp() {
        const text = generateShareableText();
        window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
    }

    function copyAllText() {
        const text = generateShareableText();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('All bank details copied! Ready to paste & send.');
            });
        }
    }

    <?php if ($autoprint): ?>
    window.addEventListener('load', function() {
        window.print();
    });
    <?php endif; ?>
    </script>
</body>
</html>
