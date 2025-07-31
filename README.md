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

## License

TBD
