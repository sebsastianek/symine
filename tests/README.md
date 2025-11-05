# Symine E2E Tests

This directory contains End-to-End (E2E) tests for the Symine project management system.

## Test Framework

We use **Symfony Panther** for E2E testing, which provides:
- Real browser automation (Chrome/Firefox)
- JavaScript execution support
- Full user interaction simulation
- Screenshot capture on failures

## Prerequisites

### 1. Install Dependencies

```bash
composer install
```

### 2. Install Browser Driver

You need either ChromeDriver or geckodriver installed:

**Option A: Using Browser Driver Installer (Recommended)**
```bash
composer require --dev dbrekelmans/bdi
vendor/bin/bdi detect drivers
```

**Option B: Manual Installation**
- **ChromeDriver**: Download from https://chromedriver.chromium.org/
- **geckodriver**: Download from https://github.com/mozilla/geckodriver/releases

### 3. Database Setup

The tests require a test database with fixtures loaded.

**Create and set up the test database:**
```bash
# Ensure MySQL is running
sudo service mysql start

# Create test database
php bin/console doctrine:database:create --env=test

# Run migrations
php bin/console doctrine:migrations:migrate --env=test --no-interaction

# Load fixtures
php bin/console doctrine:fixtures:load --env=test --no-interaction
```

## Running Tests

### Run All E2E Tests
```bash
vendor/bin/phpunit tests/E2E
```

### Run Specific Test Class
```bash
vendor/bin/phpunit tests/E2E/LoginTest.php
```

### Run Specific Test Method
```bash
vendor/bin/phpunit tests/E2E/LoginTest.php --filter testSuccessfulLoginWithAdminUser
```

### Run with Verbose Output
```bash
vendor/bin/phpunit tests/E2E --verbose
```

## Test Users

The following test users are available from fixtures:

| Username | Password | Role | Status |
|----------|----------|------|--------|
| `admin` | `admin` | Administrator | Active |
| `jsmith` | `password123` | Project Manager | Active |
| `mjohnson` | `password123` | Developer | Active |
| `sgarcia` | `password123` | Developer | Active |
| `dbrown` | `password123` | Tester | Active |
| `alee` | `password123` | Client | Active |
| `rjones` | `password123` | Inactive User | Locked |
| `guest` | `guest123` | Guest | Registered |

## Test Coverage

### Login Tests (`LoginTest.php`)

Tests the authentication system:
- ✅ Successful login with admin credentials
- ✅ Successful login with regular user credentials
- ✅ Login failure with invalid password
- ✅ Login failure with non-existent user
- ✅ Login failure with empty credentials (HTML5 validation)
- ✅ Login failure with locked user account
- ✅ Username persistence after failed login
- ✅ CSRF token validation
- ✅ Logout functionality
- ✅ Remember me functionality
- ✅ Login page accessibility

## Troubleshooting

### MySQL Connection Refused
```bash
# Check if MySQL is running
sudo service mysql status

# Start MySQL if not running
sudo service mysql start
```

### ChromeDriver Not Found
```bash
# Install using BDI
composer require --dev dbrekelmans/bdi
vendor/bin/bdi detect drivers

# Or set path manually
export PANTHER_CHROME_DRIVER_BINARY=/path/to/chromedriver
```

### Test Database Issues
```bash
# Drop and recreate test database
php bin/console doctrine:database:drop --env=test --force
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/console doctrine:fixtures:load --env=test --no-interaction
```

### Screenshot on Failure

Failed tests automatically save screenshots to `var/error-screenshots/`.
Check these screenshots to debug visual issues.

## Configuration

### Test Environment Variables

Test configuration is in `.env.test`:

```env
APP_ENV=test
DATABASE_URL="mysql://root:root@127.0.0.1:3306/redmine_test?serverVersion=8.0&charset=utf8mb4"
PANTHER_APP_ENV=panther
PANTHER_ERROR_SCREENSHOT_DIR=./var/error-screenshots
```

### PHPUnit Configuration

Test configuration is in `phpunit.xml.dist`:
- Bootstrap file: `tests/bootstrap.php`
- Test directory: `tests/E2E`
- Extensions: Symfony Bridge enabled

## Best Practices

1. **Isolation**: Each test should be independent and not rely on other tests
2. **Clean State**: Use fixtures to ensure consistent starting state
3. **Explicit Waits**: Use `waitFor()` or `waitForVisibility()` for dynamic content
4. **Descriptive Names**: Test method names should clearly describe what they test
5. **Assertions**: Use specific assertions (e.g., `assertSelectorTextContains` vs `assertStringContainsString`)
6. **Error Screenshots**: Review screenshots when tests fail to understand visual issues

## Adding New Tests

1. Create a new test class in `tests/E2E/`
2. Extend `Symfony\Component\Panther\PantherTestCase`
3. Use `static::createPantherClient()` to create a browser client
4. Write test methods with clear assertions
5. Run the test to verify it works
6. Commit the test with descriptive message

Example:
```php
<?php

namespace App\Tests\E2E;

use Symfony\Component\Panther\PantherTestCase;

class MyFeatureTest extends PantherTestCase
{
    public function testMyFeature(): void
    {
        $client = static::createPantherClient();
        $crawler = $client->request('GET', '/my-page');

        $this->assertSelectorTextContains('h1', 'Expected Title');
    }
}
```

## Continuous Integration

For CI environments, you may need to run tests in headless mode:

```bash
# Set environment variable for headless Chrome
export PANTHER_CHROME_ARGUMENTS='--headless --disable-gpu --no-sandbox'

# Run tests
vendor/bin/phpunit tests/E2E
```

## Resources

- [Symfony Panther Documentation](https://github.com/symfony/panther)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WebDriver API](https://www.selenium.dev/documentation/webdriver/)
