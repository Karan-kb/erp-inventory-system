# Matra ERP: Multi-Tenant Enterprise Resource Planning System

Matra ERP is a high-performance, scalable enterprise resource planning platform engineered with a **database-per-tenant multi-tenant architecture**. Built to handle decoupled multi-organization enterprise workflows, the system guarantees strict data isolation, high security, and performance reliability across independent corporate tenants.

---

## 🏗️ Architectural Overview & Design Patterns

### 1. Database-Per-Tenant Multi-Tenancy (SaaS Isolation)
Unlike shared-database architectures that rely on risky `tenant_id` foreign key filtering, Matra ERP provisions a **completely isolated, dedicated database schema** for each registered corporation. 
* **Data Sovereignty:** Zero risk of cross-tenant data leaks. System boundaries structurally enforce that Tenant A cannot access, query, or infer data from Tenant B.
* **Independent Scalability:** Individual tenant databases can be backed up, restored, migrated, or optimized without causing downtime or schema disruption to other tenants.
* **Dynamic Connection Switching:** The backend core intercepts inbound request contexts to dynamically resolve tenant identification and hot-swap database connection pools at runtime.

### 2. State-Synchronized Inventory Engine
The inventory subsystem operates on a strict transactional state-machine model to enforce real-time data integrity across product lifecycles:
* **Increment Vectors:** `Purchase Invoices` and `Sales Return Vouchers` trigger automated ledger adjustments to increment physical stock counts.
* **Decrement Vectors:** `Sales Invoices` and `Purchase Return Vouchers` execute downstream verification checks to decrement stock counts while mitigating race conditions.

---

## 🛠️ System Modules

* **Supply Chain Management:** End-to-end processing of Purchase Orders, Goods Received Notes (GRN), and Purchase Returns.
* **Order Management & Fulfillment:** Scalable Sales invoicing pipelines, Point-of-Sale (POS) interfaces, and Sales Returns.
* **Inventory Control & Warehousing:** Multi-branch warehouse tracking, real-time stock-flow ledgers, and safety-stock threshold alerts.
* **Double-Entry Financial Accounting:** General Ledger, isolated accounting transactions, multi-branch journal entries, and automated financial voucher generation.
* **Analytical Reporting Engines:** Computationally isolated data pipelines generating Sales Trends, Inventory Turnover Rates, Low-Stock Risk Analysis, and Tenant-specific Profit/Loss (P&L) Statements.

---

## ⚙️ Tech Stack & Infrastructure

* **Core Framework:** Laravel (PHP)
* **Database Management:** MySQL (Multi-Database Topology)
* **Asynchronous Processing:** Redis / Database Queue Workers
* **Deployment & CI/CD:** GitHub Automation Engine & Virtual Private Server (VPS) Management

---

## 🚀 Deployment & Installation Guide

### Prerequisites
* PHP >= 8.x
* Composer
* MySQL Server

### Step 1: Clone and Bootstrap Dependencies
```bash
git clone https://github.com
cd Matra-ERP
composer install
```

### Step 2: Environment Configuration
Create a `.env` file from the template and configure your master database credentials:
```bash
cp .env.example .env
php artisan key:generate
```

### Step 3: Run Master System Migrations
Execute migrations to set up the system-wide central control database (manages tenant metadata, registration, and routing):
```bash
php artisan migrate
```

### Step 4: Execute Distributed Tenant Migrations
To propagate database schema changes across all isolated tenant databases simultaneously, run the custom console command pipeline:
```bash
php artisan app:migrate-tenants
```

### Step 5: Start the Asynchronous Task Worker
Matra ERP delegates heavy reporting computations, ledger updates, and automated background tasks to an asynchronous queue pipeline to keep HTTP request cycles under 100ms. Launch the background daemon:
```bash
php artisan queue:work
```
