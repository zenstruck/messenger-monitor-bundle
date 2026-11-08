# Build a populated Messenger Monitor demo

## Create the host app

Use PHP 8.2+ with `pdo_sqlite`, Composer, and Symfony CLI. Run from the bundle checkout and use a new temporary directory.

```sh
bundle_dir="$(pwd -P)"
demo_dir=/tmp/messenger-monitor-demo
composer create-project --no-interaction 'symfony/skeleton:7.4.*' "$demo_dir"
cd "$demo_dir"
composer config repositories.monitor path "$bundle_dir"
composer require --no-interaction 'zenstruck/messenger-monitor-bundle:@dev' \
    doctrine/doctrine-bundle doctrine/orm symfony/doctrine-messenger \
    symfony/twig-bundle symfony/scheduler symfony/serializer symfony/security-csrf \
    knplabs/knp-time-bundle dragonmantank/cron-expression lorisleiva/cron-translator
```

Verify `vendor/zenstruck/messenger-monitor-bundle` links to the bundle checkout. In `config/bundles.php`, register `Zenstruck\Messenger\Monitor\ZenstruckMessengerMonitorBundle`. The Composer recipes register Doctrine, Twig, and KnpTimeBundle.

Set `DATABASE_URL="sqlite:///%kernel.project_dir%/var/demo.sqlite"` in `.env.local`. Add the `App\Entity\ProcessedMessage` entity from the bundle README's Storage section and a controller extending the bundle's `MessengerMonitorController` at `#[Route('/')]`. Use the app's attribute route import.

Add this to `config/packages/demo.yaml`. Keep the skeleton's session and secret configuration.

```yaml
framework:
    csrf_protection: true
    property_access: true
    serializer: true
    scheduler:
        enabled: true

zenstruck_messenger_monitor:
    storage:
        store_input: true
        orm:
            entity_class: App\Entity\ProcessedMessage
```

Configure `framework.messenger.failure_transport: failed` and Doctrine transports named `async`, `inventory`, `webhooks`, and `failed`. Use `doctrine://default?queue_name=<name>` for each. Use the sample message types and schedules below. Add a handler that returns a synthetic result with a reference and a processed flag.

## Populate the demo

Create the schema and queues, then write a `bin/seed` script that boots the app kernel in the same environment as the server. Seed the fresh demo with `php bin/seed`.

```sh
php bin/console doctrine:schema:create
php bin/console messenger:setup-transports
```

- Persist processed messages across the last 24 hours. Build envelopes with `MonitorStamp::from()` using ordered dispatch, receive, and finish times, a transport, memory usage, and a run ID. Add descriptions, `TagStamp` values, and synthetic `StoredInputStamp` data. Use `Results` for handler output and include failures. Give a failed attempt and its retry the same run ID and add a `RedeliveryStamp` to the retry. Tag some runs for each schedule with `TagStamp::forSchedule()`.
- Send a few envelopes to each Doctrine transport. Add `ErrorDetailsStamp` and `SentToFailureTransportStamp` with the original transport to failed envelopes so retry can requeue them.
- Seed worker states using `WorkerCache::set()` with the app's `cache.app` pool, distinct worker IDs, and `WorkerMetadata` containing `transportNames`. Include idle and processing workers and one `scheduler_<name>` worker. Simulated cache entries keep the example queues populated.

## Sample dataset

Use a fictional online shop with this mix of messages.

| Message | Transport | Sample description | Tags |
| --- | --- | --- | --- |
| `SendReceiptEmail` | `async` | Receipt for order #10482 | `email`, `orders` |
| `GenerateInvoice` | `async` | Invoice for order #10480 | `billing` |
| `SyncInventory` | `inventory` | Stock update for SKU-10481 | `warehouse`, `inventory` |
| `IndexProduct` | `inventory` | Index product #10478 | `search` |
| `DeliverWebhook` | `webhooks` | Order #10479 updated | `webhook` |

- Spread roughly 1,200 attempts over the last 24 hours, with about 3% failures. Put a webhook timeout among the six latest attempts and include a failed attempt followed by a successful retry. Vary queue wait, handling duration, and memory. Include payloads such as an order number, SKU, or an `example.test` webhook URL.
- Queue 9 messages on `async`, 4 on `inventory`, 6 on `webhooks`, and 3 failed webhooks with `webhooks` as their original transport.
- Register `maintenance` with `RefreshCatalog` every 15 minutes and `ArchiveOrders` nightly. Register `reporting` with hourly `ReconcilePayments` and a daily `SendDailyDigest`. Seed recent runs at each task's cadence.
- Show four simulated workers. Use idle workers for `async`, `webhooks`, and `scheduler_maintenance`, and a processing worker for `inventory`. Vary uptimes, handled counts, and memory across workers. Leave `reporting` without a worker so the schedule page shows both states.

Start the demo and check Dashboard, History, Statistics, a message detail, a failed transport, and a schedule. Verify that the dashboard shows sample history, workers, and queued messages and that the widget routes return populated fragments.
