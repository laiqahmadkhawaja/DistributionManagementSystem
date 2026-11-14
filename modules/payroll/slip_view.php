<?php
/**
 * Official Employee Salary Pay Slip Document View
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('accounting', 'view');

$pdo = getDatabaseConnection();
$company = getCompanyInfo($pdo);
$currUser = getCurrentUser();

$slipId = (int)($_GET['id'] ?? 0);
if (!$slipId) {
    setFlashMessage('error', 'Invalid salary slip ID.');
    redirect(BASE_URL . '/modules/payroll/slips.php');
}

$stmt = $pdo->prepare("
    SELECT ss.*, pp.month_year, pp.period_code, pp.start_date as period_start, pp.end_date as period_end,
           e.full_name as emp_name, e.employee_code, e.phone as emp_phone, e.email as emp_email,
           e.designation, e.role_type, e.commission_type, e.commission_rate,
           u.full_name as paid_by_name
    FROM `salary_slips` ss
    JOIN `payroll_periods` pp ON ss.payroll_period_id = pp.id
    JOIN `employees` e ON ss.employee_id = e.id
    LEFT JOIN `users` u ON ss.paid_by = u.id
    WHERE ss.id = ?
    LIMIT 1
");
$stmt->execute([$slipId]);
$slip = $stmt->fetch();

if (!$slip) {
    setFlashMessage('error', 'Salary slip not found.');
    redirect(BASE_URL . '/modules/payroll/slips.php');
}

$pageTitle = 'Pay Slip - ' . $slip['slip_number'];
require_once dirname(__DIR__, 2) . '/includes/header.php';

$totalEarnings = (float)$slip['base_salary'] + (float)$slip['commission_amount'] + (float)$slip['bonus_amount'] + (float)$slip['allowances'];
$totalDeductions = (float)$slip['deductions'];
$netSalary = (float)$slip['net_salary'];
?>

<!-- Document Controls Action Bar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2 d-print-none">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-ruled text-primary me-2"></i>Employee Salary Pay Slip</h4>
        <small class="text-muted">Slip: <span class="fw-bold text-dark"><?= htmlspecialchars($slip['slip_number']) ?></span> · Staff: <?= htmlspecialchars($slip['emp_name']) ?></small>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print();">
            <i class="bi bi-printer-fill me-1"></i> Print Pay Slip
        </button>
        <button type="button" class="btn btn-sm btn-whatsapp" onclick="shareSlipWhatsApp();">
            <i class="bi bi-whatsapp me-1"></i> Share via WhatsApp
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="captureAndShareElement('printableSlip', '<?= $slip['slip_number'] ?>');">
            <i class="bi bi-image me-1"></i> Download as Image
        </button>
        <a href="<?= BASE_URL ?>/modules/payroll/slips.php?period_id=<?= $slip['payroll_period_id'] ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Slips
        </a>
    </div>
</div>

<!-- Main Printable Pay Slip Sheet -->
<div id="printableSlip" class="bill-sheet mx-auto my-3" style="max-width: 860px;">
    <!-- Company Letterhead & Document Banner -->
    <div class="border-bottom pb-3 mb-3">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-primary p-2 rounded-3 text-white"><i class="bi bi-truck fs-5"></i></span>
                    <h3 class="fw-bold text-dark mb-0" style="letter-spacing: -0.5px;"><?= htmlspecialchars($company['name'] ?? APP_NAME) ?></h3>
                </div>
                <div class="text-muted small"><?= htmlspecialchars($company['address'] ?? 'Main GT Road, Gujranwala, Pakistan') ?></div>
                <div class="text-muted small">Phone: <?= htmlspecialchars($company['phone'] ?? '+92 300 0000000') ?> · Email: <?= htmlspecialchars($company['email'] ?? 'payroll@falcon.pk') ?></div>
            </div>
            <div class="text-end">
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary bill-badge-status mb-1">CONFIDENTIAL SALARY SLIP</span>
                <div class="fw-bold fs-5 text-dark mt-1"><?= htmlspecialchars($slip['slip_number']) ?></div>
                <div class="small text-muted">Period: <strong><?= htmlspecialchars($slip['month_year']) ?></strong></div>
                <div class="small text-muted">Status: <span class="badge <?= $slip['status'] === 'paid' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= strtoupper($slip['status']) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Employee Information Metadata Box -->
    <div class="bill-meta-box mb-3">
        <div class="row g-3">
            <div class="col-md-6 border-end-md">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr>
                        <td class="text-muted ps-0" style="width: 130px;">Employee Name:</td>
                        <td class="fw-bold text-dark fs-6"><?= htmlspecialchars($slip['emp_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">Staff Code / ID:</td>
                        <td class="text-dark fw-bold"><code><?= htmlspecialchars($slip['employee_code']) ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">Designation:</td>
                        <td class="text-dark"><?= htmlspecialchars($slip['designation'] ?: 'Sales & Distribution') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">System Role:</td>
                        <td class="fw-semibold text-primary"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $slip['role_type']))) ?></td>
                    </tr>
                </table>
            </div>

            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr>
                        <td class="text-muted ps-0" style="width: 140px;">Contact Phone:</td>
                        <td class="text-dark"><?= htmlspecialchars($slip['emp_phone'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">Payroll Month:</td>
                        <td class="fw-bold text-dark"><?= htmlspecialchars($slip['month_year']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">Disbursement Date:</td>
                        <td class="text-dark"><?= !empty($slip['payment_date']) ? formatDate($slip['payment_date']) : 'Pending Payout' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">Payment Method:</td>
                        <td class="text-dark fw-bold text-uppercase"><?= htmlspecialchars($slip['payment_method'] ?: 'Cash in Hand') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Salary Breakdown Table (Excel-like Grid) -->
    <div class="table-responsive mb-3">
        <table class="table table-excel align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 50%;">Earnings / Salary Head</th>
                    <th class="text-end" style="width: 50%;">Amount (PKR)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="ps-3">Basic Monthly Base Salary</td>
                    <td class="num-cell"><?= number_format((float)$slip['base_salary'], 2) ?></td>
                </tr>
                <tr>
                    <td class="ps-3 text-success">
                        Sales Performance Commission
                        <?php if ((float)$slip['sales_volume'] > 0): ?>
                            <small class="text-muted d-block" style="font-size: 0.7rem;">
                                Based on Rs. <?= number_format((float)$slip['sales_volume'], 2) ?> volume (<?= $slip['orders_count'] ?> orders) @ <?= (float)$slip['commission_rate'] ?>%
                            </small>
                        <?php endif; ?>
                    </td>
                    <td class="num-cell text-success fw-semibold"><?= number_format((float)$slip['commission_amount'], 2) ?></td>
                </tr>
                <?php if ((float)$slip['bonus_amount'] > 0): ?>
                <tr>
                    <td class="ps-3">Performance Bonus / Incentive</td>
                    <td class="num-cell text-success"><?= number_format((float)$slip['bonus_amount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ((float)$slip['allowances'] > 0): ?>
                <tr>
                    <td class="ps-3">Travel, Fuel & Field Allowances</td>
                    <td class="num-cell text-success"><?= number_format((float)$slip['allowances'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr style="background-color: #f1f8f3; font-weight: 700;">
                    <td class="ps-3 text-dark">TOTAL GROSS EARNINGS:</td>
                    <td class="num-cell text-dark"><?= number_format($totalEarnings, 2) ?></td>
                </tr>

                <!-- Deductions Section -->
                <?php if ($totalDeductions > 0): ?>
                <tr>
                    <td class="ps-3 text-danger">Less: Tax, Advances & Deductions</td>
                    <td class="num-cell text-danger">-(<?= number_format($totalDeductions, 2) ?>)</td>
                </tr>
                <?php endif; ?>

                <!-- Net Take-Home Salary -->
                <tr style="background-color: #dcfce7; font-weight: 800; font-size: 1.1rem; border-top: 2px solid #0f172a; border-bottom: 4px double #0f172a;">
                    <td class="ps-3 text-success">NET SALARY PAYABLE:</td>
                    <td class="num-cell text-success">
                        <?= DEFAULT_CURRENCY_SYMBOL ?> <?= number_format($netSalary, 2) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Official Acknowledgment & Signatures -->
    <div class="row g-4 mt-2">
        <div class="col-6">
            <div class="bill-sig-line">
                <div>Prepared & Disbursed By</div>
                <small class="text-muted fw-normal"><?= htmlspecialchars($slip['paid_by_name'] ?? 'Accounts Department') ?> · <?= htmlspecialchars($company['name'] ?? APP_NAME) ?></small>
            </div>
        </div>
        <div class="col-6">
            <div class="bill-sig-line">
                <div>Employee Acknowledgment Signature</div>
                <small class="text-muted fw-normal"><?= htmlspecialchars($slip['emp_name']) ?> (Received in Full)</small>
            </div>
        </div>
    </div>
</div>

<script>
function shareSlipWhatsApp() {
    const name = "<?= addslashes(htmlspecialchars($slip['emp_name'])) ?>";
    const slipNo = "<?= addslashes(htmlspecialchars($slip['slip_number'])) ?>";
    const month = "<?= addslashes(htmlspecialchars($slip['month_year'])) ?>";
    const base = "<?= number_format((float)$slip['base_salary'], 2) ?>";
    const comm = "<?= number_format((float)$slip['commission_amount'], 2) ?>";
    const net = "<?= number_format((float)$slip['net_salary'], 2) ?>";
    const status = "<?= strtoupper($slip['status']) ?>";
    const targetPhone = "<?= htmlspecialchars($slip['emp_phone']) ?>";

    let text = `*Falcon FMCG Distribution - Salary Pay Slip*\n`;
    text += `Slip No: *${slipNo}*\n`;
    text += `Staff Member: *${name}*\n`;
    text += `Month / Period: *${month}*\n`;
    text += `------------------------------------\n`;
    text += `Basic Salary: Rs. ${base}\n`;
    <?php if ((float)$slip['commission_amount'] > 0): ?>
    text += `Sales Commission: Rs. ${comm}\n`;
    <?php endif; ?>
    text += `*NET SALARY PAYABLE: Rs. ${net}*\n`;
    text += `Status: ${status}\n`;
    text += `------------------------------------\n`;
    text += `Falcon FMCG Distribution Co. Accounts`;

    if (typeof window.shareOnWhatsApp === 'function') {
        window.shareOnWhatsApp(targetPhone, text);
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>