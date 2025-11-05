# Symfony Redmine

🚧 **Work in Progress** 🚧

A Redmine clone built with Symfony framework.

## Status

This project is currently under development. Features and functionality are being actively implemented.

## About

This is a modern reimplementation of Redmine project management software using:
- Symfony 7
- Tailwind CSS

## Getting Started

### Option 1: Docker (Recommended)

The easiest way to get started is using Docker:

```bash
# Initialize Docker environment
./docker-init.sh

# Access the application
# http://localhost:8080
# Login: admin / admin
```

For more Docker commands, see [DOCKER.md](DOCKER.md)

### Option 2: Manual Setup

```bash
# Install dependencies
composer install
npm install

# Setup database
bin/console doctrine:database:create
bin/console doctrine:migrations:migrate

# Load fixtures (optional)
bin/console doctrine:fixtures:load

# Start development server
symfony server:start
```

## Testing

```bash
# Using Docker
./docker-test.sh

# Manual
vendor/bin/phpunit tests/E2E
```

See [tests/README.md](tests/README.md) for detailed testing documentation.

## License

TBD
