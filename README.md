# Mantra ERP: Multi-Tenant Enterprise Resource Planning Subsystem

Mantra ERP is a high-performance, scalable enterprise resource planning platform engineered with an advanced **database-per-tenant multi-tenant architecture**. Built to handle decoupled multi-organization enterprise workflows, the system structurally enforces strict data isolation, high security, and performance reliability across independent corporate tenants.

---

## 🏗️ Architectural Overview & Design Patterns

### 1. Database-Per-Tenant Multi-Tenancy (Dynamic Schema Isolation)
Unlike shared-database architectures that rely on risky `tenant_id` foreign key filtering, Mantra ERP provisions a **completely isolated, dedicated database schema** for each registered corporation. 
* **Data Sovereignty:** Eliminates the risk of cross-tenant data leaks. System boundaries structurally enforce that Tenant A cannot access, query, or infer data from Tenant B.
* **Dynamic Connection Switching:** The backend core intercepts inbound request middleware context to dynamically resolve tenant subdomains/identification and hot-swap database connection pools at runtime.
* **Independent Scalability:** Individual tenant databases can be backed up, restored, migrated, or optimized without causing downtime or schema disruption to other tenants.

### 2. State-Synchronized Inventory & Ledger Engine
The inventory subsystem operates on a strict transactional state-machine model to enforce real-time data integrity across product lifecycles:
* **Increment Vectors:** `Purchase Invoices` and `Sales Return Vouchers` trigger automated ledger adjustments to increment physical stock counts.
* **Decrement Vectors:** `Sales Invoices` and `Purchase Return Vouchers` execute downstream verification checks to decrement stock counts while mitigating race conditions.
* **Concurrency Optimization:** Enhanced MySQL indexing and custom table partitioning schemas achieve a 40% improvement in read/write operation speed under structural load tests mimicking 10,000+ daily concurrent executions.

---

## 🛠️ Core System Modules

* **Double-Entry Financial Accounting:** General Ledger, isolated accounting transactions, multi-branch journal entries, and automated financial voucher generation.
* **Supply Chain & Fulfillment:** End-to-end processing of Purchase Orders, Goods Received Notes (GRN), Sales invoicing pipelines, and Point-of-Sale (POS) interfaces.
* **Inventory Control & Warehousing:** Multi-branch warehouse tracking, real-time stock-flow ledgers, and safety-stock threshold alerts.
* **Analytical Reporting Engines:** Computationally isolated data pipelines generating Sales Trends, Inventory Turnover Rates, Low-Stock Risk Analysis, and Tenant-specific Profit/Loss (P&L) Statements.

---

## ⚙️ Tech Stack & Infrastructure

* **Core Framework:** PHP (Laravel)
* **Database Management:** MySQL (Multi-Database Topology)
* **Asynchronous Processing:** Redis / Database Queue Workers
* **Server Environment:** Nginx VPS Configuration, Ubuntu Server Administration, CI/CD Automations

---

## 🚀 Native Deployment & Installation Guide

### System Prerequisites
* Linux/Ubuntu Server or Local Development Environment
* PHP >= 8.2 (with XML, MBString, and PDO extensions)
* MySQL Server >= 8.0
* Composer

### Step 1: Clone and Bootstrap Dependencies
```bash
git clone https://github.com
cd Mantra-ERP
composer install --no-dev --optimize-autoloader
```

### Step 2: Environment Configuration
Create a production `.env` file from the template and configure your central master database credentials:
```bash
cp .env.example .env
php artisan key:generate
```

### Step 3: Run Master System Migrations
Execute migrations to set up the system-wide central control database (manages tenant metadata, registration, and dynamic routing):
```bash
php artisan migrate --force
```

### Step 4: Execute Distributed Tenant Migrations
To propagate database schema updates across all isolated tenant databases simultaneously, run the custom console command pipeline:
```bash
php artisan app:migrate-tenants
```

### Step 5: Start the Asynchronous Task Worker
Mantra ERP delegates heavy reporting computations, ledger updates, and automated background tasks to an asynchronous queue pipeline to keep HTTP request cycles under 100ms. Launch the background daemon:
```bash
php artisan queue:work --queue=default --tries=3
```
