# Docker Setup for Symine

This document describes how to set up and run Symine using Docker.

## Prerequisites

- Docker Engine 20.10+
- Docker Compose V2
- At least 4GB of available RAM
- At least 10GB of free disk space

## Quick Start

### 1. Build and Start Services

```bash
# Build Docker images
docker-compose build

# Start all services
docker-compose up -d

# Check service status
docker-compose ps
```

### 2. Initialize the Application

```bash
# Run database migrations
docker-compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# Load fixtures (sample data)
docker-compose exec app php bin/console doctrine:fixtures:load --no-interaction

# Clear cache
docker-compose exec app php bin/console cache:clear
```

### 3. Access the Application

- **Application**: http://localhost:8080
- **MySQL**: localhost:3306 (user: root, password: root)
- **MySQL Test**: localhost:3307 (user: root, password: root)

### 4. Login

Use the following credentials:
- **Admin**: username `admin`, password `admin`
- **User**: username `jsmith`, password `password123`

## Services

### Application Services

| Service | Description | Port |
|---------|-------------|------|
| `mysql` | MySQL 8.0 database (development) | 3306 |
| `mysql_test` | MySQL 8.0 database (testing) | 3307 |
| `app` | PHP-FPM application | 9000 |
| `nginx` | Nginx web server | 8080 |
| `symfony` | Symfony CLI for commands | - |
| `test` | Test runner with Chrome/Chromium | - |

## Common Tasks

### Running Symfony Commands

```bash
# General format
docker-compose exec app php bin/console <command>

# Examples
docker-compose exec app php bin/console debug:router
docker-compose exec app php bin/console make:controller
docker-compose exec app php bin/console doctrine:schema:update --dump-sql
```

### Database Management

```bash
# Create database
docker-compose exec app php bin/console doctrine:database:create

# Run migrations
docker-compose exec app php bin/console doctrine:migrations:migrate

# Load fixtures
docker-compose exec app php bin/console doctrine:fixtures:load

# Drop and recreate database
docker-compose exec app php bin/console doctrine:database:drop --force
docker-compose exec app php bin/console doctrine:database:create
docker-compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker-compose exec app php bin/console doctrine:fixtures:load --no-interaction
```

### Viewing Logs

```bash
# View all logs
docker-compose logs

# Follow logs in real-time
docker-compose logs -f

# View specific service logs
docker-compose logs -f app
docker-compose logs -f nginx
docker-compose logs -f mysql
```

### Composer Commands

```bash
# Install dependencies
docker-compose exec app composer install

# Update dependencies
docker-compose exec app composer update

# Require new package
docker-compose exec app composer require vendor/package

# Dump autoload
docker-compose exec app composer dump-autoload
```

## Running Tests

### E2E Tests with Panther

```bash
# Initialize test database
docker-compose exec test php bin/console doctrine:database:create --env=test
docker-compose exec test php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker-compose exec test php bin/console doctrine:fixtures:load --env=test --no-interaction

# Run all E2E tests
docker-compose exec test vendor/bin/phpunit tests/E2E

# Run specific test
docker-compose exec test vendor/bin/phpunit tests/E2E/LoginTest.php

# Run with verbose output
docker-compose exec test vendor/bin/phpunit tests/E2E --verbose
```

### View Test Screenshots

Failed tests save screenshots to `var/error-screenshots/`:

```bash
# List screenshots
ls -la var/error-screenshots/

# Copy screenshot from container (if needed)
docker cp symine_test:/var/www/html/var/error-screenshots/. ./var/error-screenshots/
```

## Development Workflow

### Starting Work

```bash
# Start services
docker-compose up -d

# Check everything is running
docker-compose ps

# View logs
docker-compose logs -f app
```

### Making Changes

1. Edit files on your host machine
2. Changes are automatically reflected (volumes are mounted)
3. For PHP changes, clear cache if needed:
   ```bash
   docker-compose exec app php bin/console cache:clear
   ```

### Stopping Work

```bash
# Stop services (keep data)
docker-compose stop

# Stop and remove containers (keep data)
docker-compose down

# Stop and remove everything including volumes (loses data!)
docker-compose down -v
```

## Troubleshooting

### Container Won't Start

```bash
# Check logs
docker-compose logs <service-name>

# Rebuild the service
docker-compose build --no-cache <service-name>
docker-compose up -d <service-name>
```

### Database Connection Issues

```bash
# Check MySQL is healthy
docker-compose ps mysql

# Wait for MySQL to be ready
docker-compose exec mysql mysqladmin ping -h localhost -uroot -proot

# Verify connection from app
docker-compose exec app php bin/console doctrine:schema:validate
```

### Permission Issues

```bash
# Fix var directory permissions
docker-compose exec app chown -R www-data:www-data var
docker-compose exec app chmod -R 775 var
```

### Port Already in Use

If ports 8080 or 3306 are already in use, edit `docker-compose.yml`:

```yaml
services:
  nginx:
    ports:
      - "8081:80"  # Change 8080 to 8081

  mysql:
    ports:
      - "3307:3306"  # Change 3306 to 3307
```

### Clear All Docker Resources

```bash
# Stop all containers
docker-compose down

# Remove all stopped containers
docker container prune -f

# Remove all unused images
docker image prune -a -f

# Remove all unused volumes
docker volume prune -f

# Nuclear option: remove everything
docker system prune -a --volumes -f
```

## Advanced Configuration

### Environment Variables

Create a `.env.docker.local` file for local overrides:

```env
DATABASE_URL="mysql://root:newpassword@mysql:3306/redmine_development?serverVersion=8.0&charset=utf8mb4"
APP_ENV=dev
APP_DEBUG=1
```

### Custom PHP Configuration

Edit `docker/php/php.ini` and rebuild:

```bash
docker-compose build app
docker-compose up -d app
```

### MySQL Configuration

Add custom MySQL configuration in `docker/mysql/my.cnf`:

```ini
[mysqld]
max_connections=200
innodb_buffer_pool_size=1G
```

Then rebuild:

```bash
docker-compose down
docker-compose up -d mysql
```

## Production Considerations

⚠️ **This Docker setup is optimized for development, not production.**

For production, you should:
- Use proper secrets management (not hardcoded passwords)
- Enable HTTPS with SSL certificates
- Use production-optimized PHP-FPM and Nginx configurations
- Set `APP_ENV=prod` and disable debug mode
- Use read-only filesystems where possible
- Implement proper backup strategies
- Use container orchestration (Kubernetes, Docker Swarm)
- Implement health checks and monitoring
- Use multi-stage builds to reduce image sizes

## Docker Compose Commands Reference

```bash
# Build or rebuild services
docker-compose build

# Start services
docker-compose up
docker-compose up -d  # detached mode

# Stop services
docker-compose stop

# Stop and remove containers
docker-compose down

# View running containers
docker-compose ps

# View logs
docker-compose logs
docker-compose logs -f  # follow

# Execute command in running container
docker-compose exec <service> <command>

# Scale a service
docker-compose up -d --scale app=3

# Restart a service
docker-compose restart <service>

# Pull latest images
docker-compose pull

# Validate docker-compose.yml
docker-compose config
```

## Resources

- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [Symfony Docker](https://symfony.com/doc/current/setup/docker.html)
- [PHP Docker Images](https://hub.docker.com/_/php)
