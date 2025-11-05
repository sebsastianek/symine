#!/bin/bash

# Symine Docker Test Runner
# Run E2E tests in Docker environment

set -e

echo "🧪 Symine Test Runner"
echo "===================="
echo ""

# Check if test container is running
if ! docker-compose ps test | grep -q "Up"; then
    echo "⚠️  Test container is not running. Starting services..."
    docker-compose up -d test
    sleep 5
fi

# Ensure test database is set up
echo "🗄️  Checking test database..."
docker-compose exec -T test php bin/console doctrine:database:create --env=test --if-not-exists 2>/dev/null || true
docker-compose exec -T test php bin/console doctrine:migrations:migrate --env=test --no-interaction 2>/dev/null || true
docker-compose exec -T test php bin/console doctrine:fixtures:load --env=test --no-interaction 2>/dev/null || true
echo "✅ Test database ready"
echo ""

# Run tests
echo "🚀 Running tests..."
echo ""

if [ "$#" -eq 0 ]; then
    # Run all tests
    docker-compose exec -T test vendor/bin/phpunit tests/E2E
else
    # Run specific test or with arguments
    docker-compose exec -T test vendor/bin/phpunit "$@"
fi

exit_code=$?

echo ""
if [ $exit_code -eq 0 ]; then
    echo "✅ All tests passed!"
else
    echo "❌ Some tests failed"
    echo ""
    echo "📸 Check screenshots in: var/error-screenshots/"
fi

exit $exit_code
