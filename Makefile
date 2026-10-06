PHP := /opt/homebrew/opt/php/bin/php
COMPOSER := $(PHP) /usr/local/bin/composer
ARTISAN := $(PHP) artisan

.PHONY: up down migrate fresh api queue test stan lint lint-fix openapi verify

up:        ; docker compose up -d && sleep 2 && docker compose ps
down:      ; docker compose down
migrate:   ; $(ARTISAN) migrate
fresh:     ; $(ARTISAN) migrate:fresh --seed
api:       ; $(ARTISAN) serve --host=0.0.0.0 --port=8000
queue:     ; $(ARTISAN) queue:work --queue=high,default,calendar,notifications,sync,low
test:      ; $(PHP) vendor/bin/pest
stan:      ; $(PHP) vendor/bin/phpstan analyse --memory-limit=1G
lint:      ; $(PHP) vendor/bin/pint --test
lint-fix:  ; $(PHP) vendor/bin/pint
openapi:   ; $(ARTISAN) scramble:export --path=openapi.json

verify: lint stan test
	@echo "all checks passed"
