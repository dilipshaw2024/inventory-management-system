# Operations Guide

## Prerequisites

- PHP 8.0.2 or newer
- Composer
- MySQL
- Node.js/npm for asset compilation
- A web server configured with `public/` as the document root

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure `APP_URL` and MySQL values in `.env`, then run:

```bash
php artisan migrate
npm install
npm run development
php artisan serve
```

Open the Artisan URL, register a user, and sign in. `DatabaseSeeder` currently creates no business or default user data.

## Production checklist

- Set `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY`, and the correct `APP_URL`.
- Configure database credentials, mail transport, session/cache drivers, and log retention.
- Build assets with `npm run production`.
- Point the web server at `public/`; do not expose the repository root.
- Ensure `public/upload/customer` and `public/upload/admin_images` are writable.
- Run migrations during a controlled deployment and back up the database first.
- Use HTTPS and review authorization, validation, and destructive GET routes before public exposure.

## Backup

Back up the MySQL database and both upload directories together. Database-only backups do not preserve images; file-only backups do not preserve inventory, invoices, or payment records.

## Useful commands

```bash
php artisan route:list
php artisan migrate:status
php artisan test
php artisan config:clear
php artisan view:clear
```

## Troubleshooting

| Symptom | Checks |
| --- | --- |
| Database connection failure | Verify MySQL and `DB_*` values; clear cached config |
| Images fail to upload | Check PHP upload limits and directory write permissions |
| AJAX dropdown empty | Inspect network requests; confirm the supplier/category has products |
| Invoice approval rejected | Confirm `products.quantity` covers every invoice line |
| Report is blank | Confirm date range and status `1` records |
| Assets missing | Run `npm install` and an npm build |

## Recommended hardening backlog

Add Form Request validation for all POS forms; use decimal database types for money; add foreign keys/indexes; make approval idempotent and transactional; lock product rows during approval; generate invoice numbers safely; add role/permission checks; move uploads to a managed disk; and add feature tests for every transaction path.
