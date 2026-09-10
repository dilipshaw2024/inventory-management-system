# Technical Architecture

## Stack

| Layer | Implementation |
| --- | --- |
| Runtime | PHP 8.0.2+ |
| Framework | Laravel 9.2 |
| Database | MySQL by default through Laravel configuration |
| ORM | Eloquent models |
| Templates | Blade |
| Authentication | Laravel auth middleware and Breeze-style auth controllers/views |
| Front-end build | Laravel Mix, Tailwind CSS, Alpine.js, Axios, jQuery, and bundled admin assets |
| Image processing | Intervention Image 2.7 |
| API capability | Laravel Sanctum installed; business endpoints are web/session routes |

## Request architecture

```text
Browser -> public/index.php -> Laravel bootstrap/kernel -> route
        -> middleware -> controller -> Eloquent/database
        -> Blade HTML or JSON response
```

`routes/web.php` defines public pages, authenticated admin functionality, and the three AJAX endpoints. `routes/auth.php` defines account routes. `routes/api.php` is present but contains no business API implementation.

## Repository structure

```text
app/
  Http/Controllers/        Authentication, admin, POS controllers
  Http/Middleware/         Framework middleware plus CheckAge
  Http/Requests/            Form requests used by authentication
  Models/                   Eloquent models and relationships
  Providers/                Laravel service providers
  View/Components/          Blade layout components
bootstrap/                  Framework bootstrap and cache placeholder
config/                     Application, database, auth, mail, image, and other config
database/
  migrations/               Schema history
  factories/                Model factories
  seeders/                  Seeder entry point (currently empty)
public/                     Web root, compiled assets, logo, and uploads
resources/
  css/ js/                  Source assets
  views/                    Layouts, auth, admin, POS, and printable Blade views
routes/                     Web, API, auth, channel, and console route files
tests/                      Feature auth tests and example tests
docs/                       Project documentation and Mermaid diagrams
```

## Application layers

Controllers contain most application orchestration. POS controllers directly query and mutate models, render views, and use flash notifications for redirects. Models are intentionally thin; most business relationships are defined in `Product`, `Purchase`, `Invoice`, `InvoiceDetail`, and `Payment`.

The admin layout is `resources/views/admin/admin_master.blade.php`, with header, sidebar, and footer partials under `resources/views/admin/body`. POS pages extend this layout. Guest/auth pages use `resources/views/layouts/guest.blade.php` or the auth views.

## Transactions and testing

Invoice creation and invoice approval use `DB::transaction`. Purchase creation and purchase approval do not. Payment updates are also not transactional. The repository contains authentication feature tests and examples, but no visible POS controller, stock, invoice, purchase, report, or database integration tests.

## Data and files

The database stores relative public paths for customer images and filenames for admin profile images. User uploads are placed under `public/upload`. The default filesystem is local, while the application also relies directly on `public_path` and relative public paths.
