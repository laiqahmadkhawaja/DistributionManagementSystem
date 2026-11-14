<?php
/**
 * Staff & Sales Workforce Management
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('employees', 'view');

$pdo = getDatabaseConnection();

$employees = $pdo->query("
    SELECT e.*, u.username,
           (SELECT COUNT(*) FROM `sales_orders` WHERE order_booker_id = e.id) as orders_booked,
           (SELECT COUNT(*) FROM `delivery_assignments` WHERE delivery_man_id = e.id AND status = 'delivered') as deliveries_completed
    FROM `employees` e
    LEFT JOIN `users` u ON e.user_id = u.id
    WHERE e.status = 'active'
    ORDER BY e.designation ASC, e.full_name ASC
")->fetchAll();

$pageTitle = 'Employees & Field Sales Staff';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-people text-primary me-2"></i>Employees & Sales Workforce</h4>
        <small class="text-muted">Field Sales Representatives, Delivery Drivers & Warehouse Staff</small>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-responsive-card align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee Code / Name</th>
                        <th>Designation & Role</th>
                        <th>Phone / Contact</th>
                        <th>Salary (Rs.)</th>
                        <th>Commission Model</th>
                        <th class="text-center">Performance Metric</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td data-label="Employee">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($emp['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($emp['employee_code']) ?> <?= $emp['username'] ? '(@' . htmlspecialchars($emp['username']) . ')' : '' ?></small>
                            </td>
                            <td data-label="Role">
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($emp['designation']) ?></div>
                                <span class="badge bg-light text-dark border" style="font-size: 0.7rem;"><?= htmlspecialchars(str_replace('_', ' ', $emp['role_type'])) ?></span>
                            </td>
                            <td data-label="Phone">
                                <a href="tel:<?= htmlspecialchars($emp['phone']) ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($emp['phone']) ?>
                                </a>
                            </td>
                            <td data-label="Salary" class="fw-semibold text-dark"><?= formatMoney($emp['salary']) ?></td>
                            <td data-label="Commission">
                                <?php if ($emp['commission_type'] !== 'none'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success">
                                        <?= (float)$emp['commission_rate'] ?>% <?= htmlspecialchars(str_replace('_', ' ', $emp['commission_type'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">None</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Performance" class="text-center">
                                <?php if ($emp['role_type'] === 'order_booker' || $emp['role_type'] === 'salesman'): ?>
                                    <span class="badge bg-primary rounded-pill"><?= $emp['orders_booked'] ?> Orders Booked</span>
                                <?php elseif ($emp['role_type'] === 'delivery_man'): ?>
                                    <span class="badge bg-success rounded-pill"><?= $emp['deliveries_completed'] ?> Deliveries Done</span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <span class="badge bg-success">Active</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
