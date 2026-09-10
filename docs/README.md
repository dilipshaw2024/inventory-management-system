# Inventory Management System Documentation

This directory documents the Laravel inventory and point-of-sale application as implemented in the repository.

## Documents

| Document | Purpose |
| --- | --- |
| [System overview](SYSTEM_OVERVIEW.md) | Product scope, capabilities, actors, and lifecycle summary |
| [Functional specification](FUNCTIONAL_SPECIFICATION.md) | Screen-by-screen behavior and business rules |
| [Technical architecture](TECHNICAL_ARCHITECTURE.md) | Laravel layers, request flow, assets, and integration boundaries |
| [Database reference](DATABASE.md) | Tables, columns, relationships, and stock/payment formulas |
| [Route reference](ROUTES.md) | Web, authentication, and AJAX endpoints |
| [Operations guide](OPERATIONS.md) | Local setup, deployment checklist, backup, and troubleshooting |
| [Setup and deployment](SETUP_AND_DEPLOYMENT.md) | Detailed local run, production deployment, web-server configuration, backups, and rollback |
| [Module reference](MODULE_REFERENCE.md) | Detailed purpose, behavior, relationships, data, and flow for every business module |
| [Scalability roadmap](SCALABILITY_ROADMAP.md) | Functionality required for small, medium, and enterprise company support |

## Diagrams

The diagram sources are editable Mermaid files:

- [System context](diagrams/system-context.mmd)
- [Purchase and stock flow](diagrams/purchase-stock-flow.mmd)
- [Invoice and payment flow](diagrams/invoice-payment-flow.mmd)
- [Entity relationship diagram](diagrams/entity-relationship.mmd)
- [Module relationships](diagrams/module-relationships.mmd)
- [Complete module flow](diagrams/module-flow.mmd)
- [Module dependencies](diagrams/module-dependencies.mmd)
- [Scalability roadmap](diagrams/scalability-roadmap.mmd)

GitHub, GitLab, VS Code Mermaid extensions, and Mermaid Live can render the `.mmd` sources. The Markdown documents link to the sources so they remain versionable and easy to update.

## Documentation basis

This is code-derived documentation. It reflects the routes, controllers, models, migrations, Blade views, JavaScript, and package manifests currently present in the repository. It does not assume features that are not implemented.

The application entry point is `/`. Authenticated users enter through `/login` and then `/dashboard`. The main admin navigation is defined in `resources/views/admin/body/sidebar.blade.php`.
