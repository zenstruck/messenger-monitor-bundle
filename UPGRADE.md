# Upgrade Guide

## 0.5.0

Two new `integer` columns were added to the `processed_messages` table:
`wait_time` and `handle_time`. You will need to create a migration to
add these columns to your database. They are not nullable so your
migration will need to account for existing data. You can either
truncate (purge) the `processed_messages` table have your migration
calculate these values based on the existing data.

Here's a calculation example for MySQL:

```php
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class VersionXXX extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add processed_messages.wait_time and handle_time columns';
    }

    public function up(Schema $schema): void
    {
        // Add the columns as nullable
        $this->addSql('ALTER TABLE processed_messages ADD wait_time INT DEFAULT NULL, ADD handle_time INT DEFAULT NULL');

        // set the times from existing data
        $this->addSql('UPDATE processed_messages SET wait_time = TIMESTAMPDIFF(SECOND, dispatched_at, received_at), handle_time = TIMESTAMPDIFF(SECOND, received_at, finished_at)');

        // Make the columns not nullable
        $this->addSql('ALTER TABLE processed_messages CHANGE wait_time wait_time INT NOT NULL, CHANGE handle_time handle_time INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processed_messages DROP wait_time, DROP handle_time');
    }
}
```
