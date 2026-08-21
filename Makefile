PHPUNIT  := ./src/vendor/bin/phpunit
PHPSTAN  := ./src/vendor/bin/phpstan
COMPOSER := composer

.PHONY: all test test-coverage phpstan deps clean

all: test phpstan

deps:
	cd src && $(COMPOSER) --no-scripts install

test: deps
	cp test-config/* .
	mkdir -p coverage
	$(PHPUNIT) --log-junit junit.xml --colors=never

test-coverage: deps
	cp test-config/* .
	mkdir -p coverage
	XDEBUG_MODE=coverage $(PHPUNIT) --coverage-html coverage/ --log-junit junit.xml --colors=never

phpstan: deps
	$(PHPSTAN) analyse -n --no-ansi --no-progress src/include src/filestored src/sharefsd src/dnsd src/ntpd --level 10 --memory-limit 512M

clean:
	rm -f junit.xml
	rm -rf coverage
