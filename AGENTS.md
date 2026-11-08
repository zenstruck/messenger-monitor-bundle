# Messenger Monitor Bundle

This is a Symfony bundle. See `README.md` for installation and configuration in a host app and `UPGRADE.md` for upgrade notes.

## Development

- Install development dependencies with `composer install`.
- Run `vendor/bin/phpunit` from the repo root. The local suite uses SQLite by default and needs `pdo_sqlite`.
- Run `vendor/bin/phpstan analyse` for PHP static analysis. `make cs` checks PHP formatting with Docker.
- Keep changes compatible with the PHP and Symfony versions in `composer.json`. CI also tests Doctrine storage on MySQL, MariaDB, and PostgreSQL.

## Web UI

- Twig templates live in `templates/`. `src/Controller/MessengerMonitorController.php` serves full pages and widget fragments.
- Preserve existing Twig customization blocks. For changes to a live widget, check both the initial page and the refreshed fragment in a Symfony app.
- Use the `messenger-monitor-demo` skill for browser previews and screenshots.
