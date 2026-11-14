# Enterprise Distribution Management System (DMS)

A robust, enterprise-grade FMCG Distribution Management System built with PHP 8.2+, MySQL / MariaDB InnoDB, and vanilla Bootstrap 5. Designed for high-volume wholesale FMCG distribution, field sales forces, van delivery routes, warehouse logistics, and double-entry financial accounting.

---

## 🚀 Key Modules & Capabilities

### 1. Field Sales & Order Booking
- **Order Booker Workflow:** Route-based shop visits, geolocation verification, customer catalog browsing, and instant sales order booking.
- **Multi-Tier Pricing:** Retail, Wholesale, Trade Price (TP), and Minimum Safe Price controls.
- **Offline / WebView First:** Local vendor assets with service worker sync support for field reps in low-connectivity areas.

### 2. Warehouse & Logistics Center
- **Dispatch Management:** Order picking, packing, and dispatch challan generation.
- **Vehicle Load Sheet (لوڈ شیٹ):** Consolidated vehicle loading matrices aggregating SKU totals (cartons, boxes, pieces) and shop-by-shop drop lists with loading and driver tally checkboxes.
- **Outward Security Gate Pass:** Multi-party signature verification (Storekeeper, Count Inspector, Driver, Security Officer) with compact, single-page Excel-view print formatting.
- **Multi-Warehouse Support:** Inter-depot stock transfers, bin tracking, and variance stock adjustments.

### 3. Packaging Units & Multi-Tier Conversion
- **Measurement Units:** Base units (`Piece`, `Kilogram`, `Liter`) and packaging units (`Box`, `Carton`, `Dozen`, `Pack`).
- **Multi-Tier Conversion Matrix:** Configurable conversion ratios (e.g. $1\text{ Carton} = 12\text{ Boxes} = 288\text{ Pieces}$) with global rules and product-specific overrides.

### 4. Commercial Sales Billing & Statements
- **Invoice & Bill Generation:** Clean commercial invoices showing customer balance, previous ledger outstanding, credit limits, and customizable terms of supply.
- **Single-Page Compact Print View:** Excel-like borders, zero wasteful margins, ready for dot-matrix and thermal printing.

### 5. Daily Day Closing & Cash Reconciliation (ڈیلی کلوزنگ)
- **Day-End Accounting:** Track morning starting cash, gross revenue, Cost of Goods Sold (COGS), gross trading profit, and margin percentage.
- **Cash in Hand Ledger:** Real-time reconciliation of customer recoveries, cash expenses, and supplier cash disbursements against physical cash counts (Balanced, Short, Excess).
- **Day Lock Protocol:** Complete day closing lock mechanism with historical audit logs.

### 6. Double-Entry Financial Accounting
- **Chart of Accounts:** Standard 4-tier COA (Assets, Liabilities, Equity, Revenue, Expenses).
- **Automated Journal Entries:** Real-time ledger postings on order delivery, cash receipt, and stock movement.
- **Supplier & Customer Ledgers:** Running balance statements with opening balances and printable PDF/Excel views.

### 7. Human Resources & Payroll
- **Staff Directory:** Role assignments (Super Admin, Admin, Order Booker, Salesman, Delivery Man, Storekeeper, Accountant).
- **Monthly Payroll:** Basic salary, allowances, deductions, net payable calculations, and salary slip generation.

---

## 🛠️ Technology Stack
- **Backend:** Core PHP 8.2+ (Non-MVC modular core, zero framework lock-in)
- **Database:** MySQL 8.0+ / MariaDB 10.5+ (InnoDB with strict foreign keys and transactional integrity)
- **Frontend:** Bootstrap 5.3, Bootstrap Icons, Chart.js (100% local vendor assets, zero external CDN dependency)
- **Security:** Prepared statements (PDO), CSRF tokens on all POST requests, Granular RBAC, session fixation safeguards.

---

## 📦 Installation & Setup

1. **Clone the Repository:**
   ```bash
   git clone https://github.com/laiqahmadkhawaja/DistributionManagementSystem.git
   ```

2. **Web Server Setup (WAMP / XAMPP):**
   - Place inside web root: `c:/wamp64/www/DistributionManagementSystem/`
   - Enable `mod_rewrite` and `pdo_mysql` in Apache / PHP.

3. **Database Setup:**
   - Create a MySQL database named `falcon_dms`.
   - Import `database/schema.sql` followed by `database/seed.sql`.
   - Configure credentials in `config/database.php`.

4. **Default Login Credentials:**
   - **Username:** `admin`
   - **Password:** `Password@123`

---

## 📄 License
Proprietary & Confidential. All rights reserved.
