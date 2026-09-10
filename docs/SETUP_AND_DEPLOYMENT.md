# Setup and Deployment Guide

This guide explains how to run the Inventory Management System locally and how to deploy it to a production Linux server.

## 1. Application requirements

The versions declared by the project are:

| Requirement | Version / value |
| --- | --- |
| PHP | `^8.0.2` or newer compatible PHP 8.x |
| Composer | Current Composer 2 recommended |
| Database | MySQL, configured through Laravel |
| Node.js/npm | Required to compile front-end assets |
| Framework | Laravel `^9.2` |
| Web root | The `public/` directory |

PHP should have the extensions required by Laravel and the installed Composer packages, including PDO/MySQL, OpenSSL, Mbstring, Tokenizer, XML, Ctype, JSON, Fileinfo, and GD or the image driver required by Intervention Image.

## 2. Local development setup

### Clone and install PHP dependencies

From the project directory:

```bash
composer install
```

For a production-style dependency install, use:

```bash
composer install --no-dev --optimize-autoloader
```

### Create the environment file

```bash
cp .env.example .env
php artisan key:generate
```

Do not commit `.env`. Set at least these values:

```dotenv
APP_NAME="Inventory Management System"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_management
DB_USERNAME=your_mysql_user
DB_PASSWORD=your_mysql_password
```

Create the configured MySQL database and grant the configured user access before migrating.

### Install and compile front-end assets

```bash
npm install
npm run development
```

For a minified build:

```bash
npm run production
```

### Initialize the database

```bash
php artisan migrate
```

The current `DatabaseSeeder` does not create a default user or business records. Register the first user through `/register`, or add a project-specific seeder before deployment.

To load the included example records instead, run:

```bash
php artisan db:seed
```

The example login is:

```text
Email:    demo.admin@example.com
Username: demo_admin
Password: Demo@12345
```

The seeder creates approved and pending purchase/invoice examples, linked master data, payment examples, and stock quantities suitable for demonstrating the application. Use this only for development/demo environments; do not use the example password in production.

For a high-volume dataset, run the separate bulk seeder:

```bash
php artisan db:seed --class=LargeExampleDataSeeder
```

It creates 1,000 records for each major module, including 1,000 suppliers, customers, units, categories, products, purchases, invoices, invoice details, payments, and payment-detail history rows. It creates both approved and pending transactions. It is repeatable and removes only records created by this seeder, identified by `BULK-*` and `Bulk Demo *` markers.

### Prepare upload directories

The application writes images directly into these public directories:

```text
public/upload/customer
public/upload/admin_images
```

Make sure both directories exist and are writable by the PHP process.

### Start the local server

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000`, register, verify or sign in as configured, and use the dashboard.

## 3. Local verification checklist

Run these checks after setup:

```bash
php artisan about
php artisan migrate:status
php artisan route:list
php artisan test
```

Then verify manually:

1. Register and log in.
2. Create a supplier, unit, category, and product.
3. Create and approve a purchase; confirm product quantity increases.
4. Create an invoice; confirm it appears in the pending list.
5. Approve the invoice with sufficient stock; confirm product quantity decreases.
6. Test full-due or partial payment and confirm the credit/paid lists.
7. Open stock, invoice, purchase, and customer reports.

### Dotenv formatting

Values containing spaces must be quoted in `.env`, for example:

```dotenv
APP_NAME="Inventory Management System"
```

An unquoted value such as `APP_NAME=Inventory Management System` prevents Laravel from booting with an “unexpected whitespace” dotenv error.

## 4. Production server layout

Deploy the repository outside the publicly served directory. The web server should expose only the Laravel `public/` directory.

Example:

```text
/var/www/inventory-management-system/       application root
/var/www/inventory-management-system/public web-server document root
```

Never point Apache or Nginx at the repository root, because `.env`, source code, and private files must not be web-accessible.

## 5. Production environment configuration

Use a production `.env` with values similar to:

```dotenv
APP_NAME="Inventory Management System"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://inventory.example.com

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_management
DB_USERNAME=inventory_app
DB_PASSWORD=strong-secret

CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
```

Set a real application key with `php artisan key:generate` before first use. Do not change `APP_KEY` on an existing deployment unless you understand that encrypted sessions and data may become invalid.

Configure mail settings if password resets or verification emails are required. The example environment uses MailHog and is not suitable for production mail delivery.

For a local environment without MailHog, use the log mailer:

```dotenv
MAIL_MAILER=log
```

Messages will be written to `storage/logs/laravel.log` instead of being sent. If MailHog is running through Docker, keep `MAIL_MAILER=smtp`, set `MAIL_HOST` to the reachable MailHog service name, and expose port `1025`.

For Gmail SMTP, port `465` maps to implicit SSL, so Laravel uses:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=your-gmail-address
MAIL_PASSWORD=your-gmail-app-password
MAIL_FROM_ADDRESS=your-gmail-address
```

Use a Gmail App Password rather than a normal Gmail account password. Keep SMTP credentials out of source control and rotate the supplied credential if it has been exposed in chat, logs, screenshots, or a committed `.env` file.

## 6. Production deployment procedure

Run the following from the application root during a maintenance window or controlled release:

```bash
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run production
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If the deployment uses release directories, build and migrate in the new release, switch the web-server symlink, then restart PHP-FPM. Keep the previous release available for rollback.

## 7. Nginx example

Replace the domain, PHP-FPM socket, and paths for the target server:

```nginx
server {
    listen 80;
    server_name inventory.example.com;

    root /var/www/inventory-management-system/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable HTTPS with the organization’s certificate and redirect HTTP to HTTPS before exposing the application.

## 8. Apache example

Enable the rewrite and PHP modules, then use a virtual host whose `DocumentRoot` points to `public/`:

```apache
<VirtualHost *:80>
    ServerName inventory.example.com
    DocumentRoot /var/www/inventory-management-system/public

    <Directory /var/www/inventory-management-system/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The repository includes Laravel's `public/.htaccess` behavior through the standard Laravel public entry point. Confirm `mod_rewrite` is enabled.

## 9. Permissions and ownership

The deployment user should own the application files, while the web-server/PHP user needs write access to Laravel runtime directories and upload directories. Typical writable locations are:

```text
storage/
bootstrap/cache/
public/upload/customer/
public/upload/admin_images/
```

Avoid making the entire repository world-writable. Use the least-privilege user/group arrangement provided by the server.

## 10. Database and file backups

Back up both database and uploaded files:

```bash
mysqldump -u inventory_app -p inventory_management > inventory-$(date +%F).sql
tar -czf inventory-uploads-$(date +%F).tar.gz public/upload
```

Store backups outside the web root, encrypt them where required, and periodically test restoration. A database backup alone does not restore customer/admin images.

## 11. Health checks after deployment

- Open the landing page and `/login` over HTTPS.
- Register or authenticate a test account according to the release policy.
- Confirm the database-backed dashboard loads.
- Test AJAX category/product lookup requests in the browser.
- Upload a test customer or profile image if permitted.
- Create a controlled purchase and invoice test record, then remove it according to the data-retention policy.
- Check Laravel logs in `storage/logs` and the PHP-FPM/web-server logs.

## 12. Rollback

If a release fails:

1. Stop or drain traffic if the deployment strategy requires it.
2. Switch the web-server symlink back to the previous release.
3. Restore the database only if a migration or data change requires it; take a backup before restoring.
4. Restore upload files only if the release changed or removed them.
5. Clear/rebuild cached configuration and restart PHP-FPM if needed.

Laravel migrations should be reviewed for rollback safety before production use. Avoid destructive rollback commands on a live database without an approved backup and recovery plan.

## 13. Deployment-specific risks in the current code

- The three AJAX lookup routes are outside the authenticated route group.
- Purchase approval is not transactional and can add stock twice if repeated.
- Invoice numbers are generated from the latest invoice and can collide under concurrency.
- POS forms have limited server-side validation.
- Delete operations use GET routes.
- The dashboard currently contains template/sample metrics rather than live aggregates.

Address these items before treating the application as a high-trust or high-volume production system.
