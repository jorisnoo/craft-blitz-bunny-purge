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
    vendor/bin/pest
