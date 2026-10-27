PHP := /opt/homebrew/opt/php/bin/php
COMPOSER := $(PHP) /usr/local/bin/composer
ARTISAN := $(PHP) artisan

.PHONY: install setup up down migrate fresh api queue config-clear test test-db stan lint lint-fix openapi openapi-check verify verify-ci

install:   ; $(COMPOSER) install
# Not migrate: migrate needs postgres up (`make up`), which setup must not assume. cp -n
# leaves an existing .env alone rather than clobbering it. Semicolon, not &&, deliberately:
# BSD cp (macOS, this project's target) exits 1 when -n skips an existing file, unlike GNU
# cp which exits 0 - && would then skip key:generate on every machine that already has a
# .env, which is the common case for a repeat `make setup`, not just the first one.
setup:     install ; cp -n .env.example .env; $(ARTISAN) key:generate

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
openapi:   ; ./bin/generate-openapi.sh openapi.json
openapi-check: ; ./bin/check-openapi.sh

verify: lint stan openapi-check test-db test
	@echo "all checks passed"

# What CI actually runs - one definition of the gate, not two free to diverge (I10). Takes
# `COMPOSER` (hardcodes a path that does not exist on a runner) and `test-db` (shells into
# `docker compose`, which CI's services: block does not provide) out of its path; CI creates
# the test database itself with `psql`/`createdb` instead. `make install` and migrating are
# left to the workflow too, for the same reason `verify` leaves them to `make setup`/`up`:
# this target is the gate, not the environment that makes the gate meaningful to run.
# Invoked as `make verify-ci PHP=php` - a command-line assignment, which GNU Make exports to
# every recipe's environment, so both `$(PHP)` (used textually by lint/stan/test below) and
# bin/check-openapi.sh's own `${PHP:-...}` default (read from the environment, not from
# Make) pick up the same override. See bin/check-openapi.sh's own comment for why that
# script reads PHP from the environment rather than taking it as an argument.
verify-ci: config-clear lint stan openapi-check test
	@echo "all checks passed"
