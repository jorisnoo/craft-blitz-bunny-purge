# List available recipes
default:
    @just --list

# Install dependencies
install:
    composer install

# Update dependencies
update:
    composer update

# Run tests
test:
    bash scripts/with-test-mysql.sh vendor/bin/pest
