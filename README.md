## Matra ERP

In our ERP system, we use a multi-tenant architecture with separate databases for each company. This means every company operates independently with its own dedicated database, ensuring strong data isolation, security, and scalability.

Each company has:

its own database,
separate purchase and sales records,
independent inventory management,
isolated accounting transactions,
and branch-specific operations.

For example:

Company A cannot access Company B’s data,
inventory quantities are tracked separately for each company,
reports and vouchers are generated independently,
and each company maintains its own fiscal-year transactions and configurations.

The ERP manages modules such as:

Purchase
Purchase Return
Sales
Sales Return
Inventory Tracking
Voucher & Accounting
Reporting 

The system continuously tracks product quantities in real time:

purchases increase stock,
sales decrease stock,
purchase returns deduct stock,
sales returns restore stock.

This helps maintain accurate inventory flow across all operations.

Using separate databases for each tenant/company provides several advantages:

improved security,
easier backup and recovery,
better performance isolation,
easier company-level customization,
and scalable enterprise deployment.

The ERP also generates business growth reports such as:

sales growth reports,
inventory movement reports,
low stock analysis,
profit/loss summaries,
branch-wise reports,
and transaction history reports.

This architecture makes the ERP suitable for handling multiple organizations efficiently while maintaining secure and organized business operations.

# Running Project

-   git pull origin main
-   composer install
-   php artisan migrate



# Running migration in tenant companies
-   php artisan app:migrate-tenants

# Run Queue/Socket

1. Queue

```
 php artisan queue:work

```


