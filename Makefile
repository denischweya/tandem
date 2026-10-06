PHP := /opt/homebrew/opt/php/bin/php
COMPOSER := $(PHP) /usr/local/bin/composer
ARTISAN := $(PHP) artisan

.PHONY: up down migrate fresh api queue config-clear test test-db stan lint lint-fix openapi openapi-check verify

up:        ; docker compose up -d && sleep 2 && docker compose ps
down:      ; docker compose down
migrate:   ; $(ARTISAN) migrate
fresh:     ; $(ARTISAN) migrate:fresh --seed
api:       ; $(ARTISAN) serve --host=0.0.0.0 --port=8000
queue:     ; $(ARTISAN) queue:work --queue=high,default,calendar,notifications,sync,low

# Mirrors composer.json's own "test" script, which runs this before its own test step.
# Without it, a stray bootstrap/cache/config.php (left by `artisan optimize` or
# `artisan config:cache`) silently retargets the whole suite at whatever database .env
# names - see tests/TestCase.php::guardTestDatabase() for the runtime guard this backs up.
config-clear: ; $(ARTISAN) config:clear
test:      config-clear ; $(PHP) vendor/bin/pest

test-db:
	@docker compose exec -T postgres psql -U tandem -d tandem -tc \
	  "SELECT 1 FROM pg_database WHERE datname = 'tandem_test'" | grep -q 1 \
	  || docker compose exec -T postgres createdb -U tandem tandem_test
	@echo "tandem_test ready"

stan:      ; $(PHP) vendor/bin/phpstan analyse --memory-limit=1G
lint:      ; $(PHP) vendor/bin/pint --test
lint-fix:  ; $(PHP) vendor/bin/pint
openapi:   ; $(ARTISAN) scramble:export --path=openapi.json --fail-on-unknown
openapi-check: ; ./bin/check-openapi.sh

verify: lint stan openapi-check test-db test
	@echo "all checks passed"
