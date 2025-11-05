#!/bin/bash

# Symine Docker Initialization Script
# This script sets up the Docker environment and initializes the database

set -e

echo "🐳 Symine Docker Initialization"
echo "================================"
echo ""

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker is not running. Please start Docker first."
    exit 1
fi

echo "✅ Docker is running"
echo ""

# Build Docker images
echo "📦 Building Docker images..."
docker-compose build
echo "✅ Docker images built"
echo ""

# Start services
echo "🚀 Starting services..."
docker-compose up -d
echo "✅ Services started"
echo ""

# Wait for MySQL to be ready
echo "⏳ Waiting for MySQL to be ready..."
max_attempts=30
attempt=0

while ! docker-compose exec -T mysql mysqladmin ping -h localhost -uroot -proot --silent > /dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ $attempt -ge $max_attempts ]; then
        echo "❌ MySQL did not become ready in time"
        exit 1
    fi
    echo "   Attempt $attempt/$max_attempts - waiting..."
    sleep 2
done

echo "✅ MySQL is ready"
echo ""

# Run migrations
echo "🗄️  Running database migrations..."
docker-compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction
echo "✅ Migrations complete"
echo ""

# Load fixtures
echo "📥 Loading fixtures (sample data)..."
docker-compose exec -T app php bin/console doctrine:fixtures:load --no-interaction
echo "✅ Fixtures loaded"
echo ""

# Clear cache
echo "🧹 Clearing cache..."
docker-compose exec -T app php bin/console cache:clear
echo "✅ Cache cleared"
echo ""

# Initialize test database
echo "🧪 Setting up test database..."
docker-compose exec -T test php bin/console doctrine:database:create --env=test --if-not-exists
docker-compose exec -T test php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker-compose exec -T test php bin/console doctrine:fixtures:load --env=test --no-interaction
echo "✅ Test database ready"
echo ""

echo "🎉 Initialization complete!"
echo ""
echo "📋 Next steps:"
echo "   - Application: http://localhost:8080"
echo "   - Login with: admin / admin"
echo "   - View logs: docker-compose logs -f"
echo "   - Run tests: docker-compose exec test vendor/bin/phpunit tests/E2E"
echo ""
echo "💡 Tip: Run './docker-help.sh' for more commands"
