<?php
/**
 * Official Customer Payment Details & Counter Display Portal
 * Dedicated shareable, responsive, and counter-display page for official company bank accounts & mobile wallets
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

$bankAccounts = array_values(array_filter($accounts, fn($a) => $a['account_type'] === 'bank'));
$walletAccounts = array_values(array_filter($accounts, fn($a) => in_array($a['account_type'], ['wallet', 'raast', 'other'])));

$autoprint = isset($_GET['print']) && $_GET['print'] == '1';

// Brand color resolver helper
function getBrandTheme(string $bankName, string $type): array {
    $name = strtolower($bankName);
    if (str_contains($name, 'meezan')) {
        return [
            'bg' => 'linear-gradient(135deg, #064e3b 0%, #047857 100%)',
            'border' => '#059669',
            'accent' => '#34d399',
            'text' => '#ffffff',
            'tag' => 'bg-emerald text-white',
            'icon' => 'bi-shield-check'
        ];
    } elseif (str_contains($name, 'hbl') || str_contains($name, 'habib')) {
        return [
            'bg' => 'linear-gradient(135deg, #064e3b 0%, #0d9488 100%)',
            'border' => '#0d9488',
            'accent' => '#2dd4bf',
            'text' => '#ffffff',
            'tag' => 'bg-teal text-white',
            'icon' => 'bi-bank'
        ];
    } elseif (str_contains($name, 'jazzcash')) {
        return [
            'bg' => 'linear-gradient(135deg, #7c2d12 0%, #ea580c 100%)',
            'border' => '#f97316',
            'accent' => '#fdba74',
            'text' => '#ffffff',
            'tag' => 'bg-warning text-dark',
            'icon' => 'bi-phone'
        ];
    } elseif (str_contains($name, 'easypaisa')) {
        return [
            'bg' => 'linear-gradient(135deg, #14532d 0%, #16a34a 100%)',
            'border' => '#22c55e',
            'accent' => '#86efac',
            'text' => '#ffffff',
            'tag' => 'bg-success text-white',
            'icon' => 'bi-wallet2'
        ];
    } elseif (str_contains($name, 'sada') || str_contains($name, 'sadapay')) {
        return [
            'bg' => 'linear-gradient(135deg, #881337 0%, #f43f5e 100%)',
            'border' => '#fb7185',
            'accent' => '#fecdd3',
            'text' => '#ffffff',
            'tag' => 'bg-danger text-white',
            'icon' => 'bi-credit-card-2-front'
        ];
    } elseif (str_contains($name, 'alfalah')) {
        return [
            'bg' => 'linear-gradient(135deg, #7f1d1d 0%, #b91c1c 100%)',
            'border' => '#dc2626',
            'accent' => '#fca5a5',
            'text' => '#ffffff',
            'tag' => 'bg-danger text-white',
            'icon' => 'bi-bank'
        ];
    } elseif ($type === 'bank') {
        return [
            'bg' => 'linear-gradient(135deg, #0f172a 0%, #1e293b 100%)',
            'border' => '#3b82f6',
            'accent' => '#60a5fa',
            'text' => '#ffffff',
            'tag' => 'bg-primary text-white',
            'icon' => 'bi-bank2'
        ];
    } else {
        return [
            'bg' => 'linear-gradient(135deg, #1e1b4b 0%, #4338ca 100%)',
            'border' => '#6366f1',
            'accent' => '#a5b4fc',
            'text' => '#ffffff',
            'tag' => 'bg-indigo text-white',
            'icon' => 'bi-phone'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Official Payment Portal - <?= htmlspecialchars($company['name'] ?? APP_NAME) ?></title>
    
    <!-- Local Vendor CSS (Offline & Zero External CDN Dependencies) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
    
    <style>
        :root {
            --brand-primary: #1e3a8a;
            --brand-accent: #3b82f6;
            --surface-bg: #f8fafc;
            --card-border: #e2e8f0;
        }

        body {
            background-color: var(--surface-bg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #0f172a;
            min-height: 100vh;
        }

        /* Top Bar */
        .portal-nav {
            background: #0f172a;
            color: #fff;
            padding: 10px 16px;
            border-bottom: 1px solid #1e293b;
        }

        /* Hero Banner */
        .portal-hero {
            background: linear-gradient(135deg, #091326 0%, #172554 50%, #1e3a8a 100%);
            color: #ffffff;
            padding: 36px 0 32px 0;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.15);
        }

        .portal-hero::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.18) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        /* Bank / Wallet Card - Modern Executive Design */
        .fintech-card {
            border-radius: 16px;
            color: #ffffff;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.12), 0 8px 10px -6px rgba(15, 23, 42, 0.08);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.12);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .fintech-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 30px -10px rgba(15, 23, 42, 0.22);
        }

        .fintech-card.is-primary-card::before {
            content: "★ PRIMARY SETTLEMENT ACCOUNT";
            position: absolute;
            top: 0;
            right: 0;
            background: #f59e0b;
            color: #0f172a;
            font-size: 0.65rem;
            font-weight: 800;
            padding: 4px 14px;
            border-bottom-left-radius: 10px;
            letter-spacing: 0.5px;
            z-index: 5;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }

        .card-inner-box {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 12px;
            padding: 10px 14px;
        }

        .account-digits-display {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            color: #ffffff;
        }

        .iban-digits-display {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.92rem;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.95);
            word-break: break-all;
        }

        .btn-copy-interactive {
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 5px 12px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .btn-copy-interactive:hover {
            background: #ffffff;
            color: #0f172a;
            border-color: #ffffff;
        }

        .sim-chip {
            width: 38px;
            height: 28px;
            background: linear-gradient(135deg, #fbbf24 0%, #d97706 100%);
            border-radius: 6px;
            position: relative;
            border: 1px solid #fef3c7;
            display: inline-block;
        }

        .sim-chip::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: rgba(0,0,0,0.25);
        }

        .sim-chip::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 50%;
            width: 1px;
            background: rgba(0,0,0,0.25);
        }

        /* Filter Pills */
        .filter-pill {
            cursor: pointer;
            padding: 6px 18px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            transition: all 0.2s ease;
        }

        .filter-pill.active {
            background: #1e3a8a;
            color: #ffffff;
            border-color: #1e3a8a;
            box-shadow: 0 4px 8px -2px rgba(30, 58, 138, 0.25);
        }

        /* Step Card */
        .step-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            height: 100%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
            transition: all 0.2s;
        }

        .step-badge {
            width: 32px;
            height: 32px;
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 800;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            font-size: 0.9rem;
            border: 1px solid #bfdbfe;
        }

        /* Toast Feedback */
        #portalToast {
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        /* Counter Notice Print Styles */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .portal-hero {
                background: none !important;
                color: #000000 !important;
                padding: 12px 0 !important;
                box-shadow: none !important;
                border-bottom: 2px solid #000000 !important;
            }
            .portal-hero h3, .portal-hero p, .portal-hero div {
                color: #000000 !important;
            }
            .fintech-card {
                background: #ffffff !important;
                color: #000000 !important;
                border: 2px solid #000000 !important;
                box-shadow: none !important;
                page-break-inside: avoid;
                margin-bottom: 16px;
            }
            .card-inner-box {
                background: #f8fafc !important;
                border: 1px solid #000000 !important;
            }
            .account-digits-display, .iban-digits-display {
                color: #000000 !important;
            }
            .btn-copy-interactive, .sim-chip {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <!-- Top Action Toolbar (Hidden in Print) -->
    <div class="portal-nav no-print">
        <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>/modules/accounts/index.php" class="btn btn-sm btn-outline-light d-inline-flex align-items-center gap-1">
                    <i class="bi bi-arrow-left"></i> Manage Accounts
                </a>
                <span class="text-secondary small d-none d-md-inline">|</span>
                <span class="text-light small fw-semibold d-none d-md-inline">Official Customer Counter Display</span>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-light fw-bold" onclick="window.print();">
                    <i class="bi bi-printer me-1"></i> Print Counter View
                </button>
                <button type="button" class="btn btn-sm btn-success fw-bold" onclick="shareOnWhatsApp();">
                    <i class="bi bi-whatsapp me-1"></i> Share via WhatsApp
                </button>
                <button type="button" class="btn btn-sm btn-outline-light" onclick="copyAllText();">
                    <i class="bi bi-clipboard me-1"></i> Copy All
                </button>
            </div>
        </div>
    </div>

    <!-- Official Corporate Hero Banner -->
    <header class="portal-hero">
        <div class="container text-center">
            <div class="d-inline-flex align-items-center gap-2 px-3 py-1 bg-white bg-opacity-10 border border-white border-opacity-20 rounded-pill mb-2 small text-light">
                <i class="bi bi-shield-fill-check text-success"></i>
                <span>Official Verified Corporate Settlement Accounts</span>
            </div>
            <h2 class="fw-bold mb-1 tracking-tight"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h2>
            <p class="mb-2 text-white-50 small">
                <?= htmlspecialchars($company['address'] ?? 'Central Distribution Hub, Gujranwala') ?> 
                • Helpline / WhatsApp: <strong class="text-white"><?= htmlspecialchars($company['phone'] ?? '+92 300 1111111') ?></strong>
            </p>
            <div class="text-white-50" style="font-size: 0.78rem;">
                Authorized FMCG Distribution & Supply Chain · Fast Settlement via Online Transfer, ATM & Raast Instant Pay
            </div>
        </div>
    </header>

    <div class="container py-4">

        <!-- 3-Step Visual Transfer Guide -->
        <div class="row g-3 mb-4 no-print">
            <div class="col-12 col-md-4">
                <div class="step-card">
                    <div class="step-badge">1</div>
                    <div class="fw-bold text-dark small mb-1">Select Bank or Mobile Wallet</div>
                    <div class="text-muted" style="font-size: 0.78rem;">
                        Choose your preferred bank account or mobile wallet below and click <strong>Copy</strong> to copy the account or IBAN.
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="step-card">
                    <div class="step-badge">2</div>
                    <div class="fw-bold text-dark small mb-1">Make Instant Online Transfer</div>
                    <div class="text-muted" style="font-size: 0.78rem;">
                        Use your Mobile Banking App, Raast, ATM, or teller. Please ensure the beneficiary title matches <strong class="text-dark">Falcon</strong>.
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="step-card">
                    <div class="step-badge">3</div>
                    <div class="fw-bold text-dark small mb-1">Send Screenshot on WhatsApp</div>
                    <div class="text-muted" style="font-size: 0.78rem;">
                        Send deposit receipt / TID with your <strong>Shop Name</strong> to <strong class="text-success"><?= htmlspecialchars($company['phone'] ?? '') ?></strong> for immediate ledger credit.
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Pills Bar & Counter Count -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2 no-print">
            <div class="d-flex gap-2">
                <button type="button" class="filter-pill active" onclick="filterChannel('all', this)">
                    All Channels (<?= count($accounts) ?>)
                </button>
                <?php if (!empty($bankAccounts)): ?>
                    <button type="button" class="filter-pill" onclick="filterChannel('bank', this)">
                        <i class="bi bi-bank me-1"></i> Commercial Banks (<?= count($bankAccounts) ?>)
                    </button>
                <?php endif; ?>
                <?php if (!empty($walletAccounts)): ?>
                    <button type="button" class="filter-pill" onclick="filterChannel('wallet', this)">
                        <i class="bi bi-phone me-1"></i> Mobile Wallets (<?= count($walletAccounts) ?>)
                    </button>
                <?php endif; ?>
            </div>
            <div class="text-muted small">
                <i class="bi bi-lock-fill text-success me-1"></i>100% Encrypted & Authenticated Company Channels
            </div>
        </div>

        <!-- Empty State (if no accounts configured) -->
        <?php if (empty($accounts)): ?>
            <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
                <div class="text-muted mb-3"><i class="bi bi-bank fs-1"></i></div>
                <h5 class="fw-bold text-dark">No Payment Accounts Configured Yet</h5>
                <p class="text-muted small">Please configure company bank accounts and mobile wallets in Accounts Management.</p>
                <div class="mt-2">
                    <a href="<?= BASE_URL ?>/modules/accounts/index.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle me-1"></i> Add Bank Account
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Accounts Grid -->
        <div class="row g-4" id="accountsGrid">
            <?php foreach ($accounts as $acc): 
                $theme = getBrandTheme($acc['bank_name'], $acc['account_type']);
                $formattedIban = !empty($acc['iban']) ? trim(chunk_split(str_replace(' ', '', $acc['iban']), 4, ' ')) : '';
            ?>
                <div class="col-12 col-md-6 col-lg-6 account-item" data-type="<?= $acc['account_type'] ?>">
                    <div class="fintech-card p-4 h-100 <?= $acc['is_primary'] ? 'is-primary-card' : '' ?>" style="background: <?= $theme['bg'] ?>; border-color: <?= $theme['border'] ?>;">
                        
                        <!-- Card Header -->
                        <div>
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi <?= $theme['icon'] ?> fs-4" style="color: <?= $theme['accent'] ?>"></i>
                                        <h4 class="fw-bold mb-0 text-white"><?= htmlspecialchars($acc['bank_name']) ?></h4>
                                    </div>
                                    <?php if (!empty($acc['branch_name'])): ?>
                                        <div class="text-white-50 small mt-1">
                                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($acc['branch_name']) ?>
                                            <?php if (!empty($acc['branch_code'])): ?>
                                                <span class="opacity-75">(Code: <?= htmlspecialchars($acc['branch_code']) ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="sim-chip no-print" title="EMV Smart Chip"></div>
                                    <i class="bi bi-wifi fs-4 text-white-50 no-print" title="Contactless Online Settlement"></i>
                                </div>
                            </div>

                            <!-- Account Title -->
                            <div class="mb-3">
                                <div class="text-white-50 small text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">Account Title / Beneficiary</div>
                                <div class="fs-5 fw-bold text-white d-flex align-items-center gap-2">
                                    <span><?= htmlspecialchars($acc['account_title']) ?></span>
                                    <span class="badge bg-success bg-opacity-75 text-white" style="font-size: 0.65rem;">
                                        <i class="bi bi-check-circle me-1"></i>Verified Title
                                    </span>
                                </div>
                            </div>

                            <!-- Account Number Block -->
                            <div class="card-inner-box mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-white-50 text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                                        <?= $acc['account_type'] === 'bank' ? 'Account Number' : 'Mobile / Wallet Number' ?>
                                    </span>
                                    <span class="text-white-50 small" style="font-size: 0.65rem;">Tap Copy</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="account-digits-display"><?= htmlspecialchars($acc['account_number']) ?></span>
                                    <button type="button" class="btn btn-copy-interactive" onclick="copyValue('<?= htmlspecialchars($acc['account_number']) ?>', this)">
                                        <i class="bi bi-clipboard me-1"></i> Copy
                                    </button>
                                </div>
                            </div>

                            <!-- IBAN Block (If available) -->
                            <?php if (!empty($acc['iban'])): ?>
                                <div class="card-inner-box mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="text-white-50 text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">IBAN (International Bank Account)</span>
                                        <span class="text-white-50 small" style="font-size: 0.65rem;">24 Digits</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center gap-2">
                                        <span class="iban-digits-display"><?= htmlspecialchars($formattedIban) ?></span>
                                        <button type="button" class="btn btn-copy-interactive" onclick="copyValue('<?= htmlspecialchars($acc['iban']) ?>', this)">
                                            <i class="bi bi-clipboard me-1"></i> Copy
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Raast Fast Payment ID (If available) -->
                            <?php if (!empty($acc['raast_id'])): ?>
                                <div class="d-flex justify-content-between align-items-center p-2 rounded-3 mb-2" style="background: rgba(251, 191, 36, 0.15); border: 1px solid rgba(251, 191, 36, 0.35);">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-lightning-charge-fill text-warning fs-5"></i>
                                        <div>
                                            <div class="text-white fw-bold small" style="font-size: 0.78rem;">Raast Instant Pay ID</div>
                                            <div class="text-warning fw-bold font-monospace"><?= htmlspecialchars($acc['raast_id']) ?></div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-warning text-white btn-copy-interactive" onclick="copyValue('<?= htmlspecialchars($acc['raast_id']) ?>', this)">
                                        <i class="bi bi-clipboard me-1"></i> Copy Raast
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Footer / Notes -->
                        <div class="pt-2 border-top border-white border-opacity-10 d-flex justify-content-between align-items-center" style="font-size: 0.72rem;">
                            <span class="text-white-50">
                                <i class="bi bi-shield-check me-1"></i>24/7 Fast Settlement
                            </span>
                            <?php if (!empty($acc['notes'])): ?>
                                <span class="text-white-50 text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($acc['notes']) ?>">
                                    <?= htmlspecialchars($acc['notes']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-white-50">Instant Ledger Verification</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Security Notice & Direct Helpline Card -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mt-5 bg-white border-start border-4 border-primary">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                <div>
                    <h5 class="fw-bold text-dark mb-1">
                        <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Security & Anti-Fraud Advisory
                    </h5>
                    <div class="text-muted small" style="max-width: 750px;">
                        Always ensure that the beneficiary name appears as <strong class="text-dark">Falcon</strong> before approving online payments. Do not hand over physical cash to unauthorized field agents without an official digital recovery receipt voucher (`RCP-`).
                    </div>
                </div>
                <div class="d-flex gap-2 no-print">
                    <button type="button" class="btn btn-success fw-bold d-inline-flex align-items-center gap-2" onclick="shareOnWhatsApp();">
                        <i class="bi bi-whatsapp fs-5"></i> Send Receipt on WhatsApp
                    </button>
                </div>
            </div>
        </div>

        <!-- Footer Copyright & Identification -->
        <footer class="text-center py-4 mt-3 text-muted small">
            <div><strong><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></strong> — Official Distribution Accounts & Settlements</div>
            <div>Helpline: <?= htmlspecialchars($company['phone'] ?? '+92 300 1111111') ?> • Gujranwala, Pakistan</div>
            <div class="mt-2 text-muted" style="font-size: 0.7rem;">Powered by Falcon Enterprise DMS 2.0</div>
        </footer>
    </div>

    <!-- Floating Toast Notification Feedback -->
    <div id="portalToast" class="position-fixed bottom-0 start-50 translate-middle-x mb-4 px-4 py-2 bg-dark text-white rounded-pill shadow-lg d-none" style="z-index: 9999; font-size: 0.88rem; opacity: 0;">
        <i class="bi bi-check-circle-fill text-success me-2"></i>
        <span id="toastMessage">Copied to clipboard!</span>
    </div>

    <script>
    // Copy Value with Interactive Feedback
    function copyValue(text, btnElem) {
        if (!text) return;
        
        // Remove spaces for clean copy if needed
        const cleanText = text.trim();

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(cleanText).then(() => {
                showToastFeedback('Copied: ' + cleanText);
                triggerBtnState(btnElem);
            }).catch(() => {
                fallbackCopy(cleanText, btnElem);
            });
        } else {
            fallbackCopy(cleanText, btnElem);
        }
    }

    function fallbackCopy(text, btnElem) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            showToastFeedback('Copied: ' + text);
            triggerBtnState(btnElem);
        } catch (err) {
            alert('Please copy manually: ' + text);
        }
        document.body.removeChild(textArea);
    }

    function triggerBtnState(btnElem) {
        if (!btnElem) return;
        const originalHtml = btnElem.innerHTML;
        btnElem.innerHTML = '<i class="bi bi-check-lg text-success"></i> Copied!';
        btnElem.classList.add('bg-white', 'text-dark');
        setTimeout(() => {
            btnElem.innerHTML = originalHtml;
            btnElem.classList.remove('bg-white', 'text-dark');
        }, 1800);
    }

    function showToastFeedback(msg) {
        const toast = document.getElementById('portalToast');
        const msgElem = document.getElementById('toastMessage');
        if (!toast || !msgElem) return;

        msgElem.textContent = msg;
        toast.classList.remove('d-none');
        setTimeout(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translate(-50%, -8px)';
        }, 10);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translate(-50%, 0)';
            setTimeout(() => {
                toast.classList.add('d-none');
            }, 250);
        }, 2200);
    }

    // Client-side Channel Filtering
    function filterChannel(type, btnElem) {
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        if (btnElem) btnElem.classList.add('active');

        const items = document.querySelectorAll('.account-item');
        items.forEach(it => {
            const itemType = it.getAttribute('data-type');
            if (type === 'all') {
                it.style.display = '';
            } else if (type === 'bank') {
                it.style.display = (itemType === 'bank') ? '' : 'none';
            } else if (type === 'wallet') {
                it.style.display = (itemType !== 'bank') ? '' : 'none';
            }
        });
    }

    // WhatsApp Structured Message Generator
    function generateShareText() {
        let text = `*OFFICIAL COMPANY BANK & PAYMENT ACCOUNTS*\n`;
        text += `*<?= addslashes(htmlspecialchars($company['name'] ?? APP_NAME)) ?>*\n`;
        text += `Helpline / WhatsApp: <?= addslashes(htmlspecialchars($company['phone'] ?? '+92 300 1111111')) ?>\n`;
        text += `====================================\n\n`;
        text += `Dear Customer, please transfer your order payments to any of our official verified accounts below:\n\n`;

        <?php foreach ($accounts as $idx => $acc): ?>
            text += `*<?= ($idx + 1) ?>. <?= addslashes(htmlspecialchars($acc['bank_name'])) ?>*\n`;
            text += `• Title: *<?= addslashes(htmlspecialchars($acc['account_title'])) ?>*\n`;
            text += `• Account #: *<?= addslashes(htmlspecialchars($acc['account_number'])) ?>*\n`;
            <?php if (!empty($acc['iban'])): ?>
            text += `• IBAN: *<?= addslashes(htmlspecialchars($acc['iban'])) ?>*\n`;
            <?php endif; ?>
            <?php if (!empty($acc['raast_id'])): ?>
            text += `• Raast ID: *<?= addslashes(htmlspecialchars($acc['raast_id'])) ?>*\n`;
            <?php endif; ?>
            <?php if (!empty($acc['branch_name'])): ?>
            text += `• Branch: <?= addslashes(htmlspecialchars($acc['branch_name'])) ?>\n`;
            <?php endif; ?>
            text += `\n`;
        <?php endforeach; ?>

        text += `------------------------------------\n`;
        text += `*Important:* After payment, please share the transfer receipt screenshot with your *Shop Name* on this WhatsApp for immediate ledger credit.\n`;
        text += `Thank you!`;
        return text;
    }

    function shareOnWhatsApp() {
        const msg = generateShareText();
        const url = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(msg);
        window.open(url, '_blank');
    }

    function copyAllText() {
        const msg = generateShareText();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(msg).then(() => {
                showToastFeedback('All official bank details copied to clipboard!');
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
