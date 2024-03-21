.PHONY: test composer csfix phpstan cs check

TARGET := $(filter-out $(firstword $(MAKECMDGOALS)), $(MAKECMDGOALS))
FIXER_VERSION := 3-php8.2
PHP_VERSION := 8.2-cli

composer:
	 docker run --rm --interactive --tty \
	 --volume $$PWD/tmp:/tmp:z \
	 --volume $$PWD:/app:z \
	 composer $(TARGET)

phpstan:
	docker run -it --rm --name messenger-monitor-bundle -v $$PWD:/usr/src/myapp:z -w /usr/src/myapp php:$(PHP_VERSION) php vendor/bin/phpstan

test:
	docker run -it --rm --name messenger-monitor-bundle -v $$PWD:/usr/src/myapp:z -w /usr/src/myapp php:$(PHP_VERSION) php vendor/bin/phpunit

csfix:
	docker run -v $$PWD:/code:z ghcr.io/php-cs-fixer/php-cs-fixer:$(FIXER_VERSION) fix --config=.php-cs-fixer.dist.php

cs:
	docker run -v $$PWD:/code:z ghcr.io/php-cs-fixer/php-cs-fixer:$(FIXER_VERSION) fix --config=.php-cs-fixer.dist.php --dry-run --diff

check: cs phpstan test
