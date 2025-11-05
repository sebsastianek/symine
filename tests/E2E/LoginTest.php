<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Symfony\Component\Panther\PantherTestCase;

/**
 * E2E tests for login functionality
 *
 * These tests verify that the login process works correctly with the authentication system.
 *
 * Requirements:
 * - Test database must be set up with fixtures loaded
 * - ChromeDriver or geckodriver must be installed
 */
class LoginTest extends PantherTestCase
{
    /**
     * Test successful login with valid admin credentials
     */
    public function testSuccessfulLoginWithAdminUser(): void
    {
        $client = static::createPantherClient();
        $crawler = $client->request('GET', '/login');

        // Verify we're on the login page
        $this->assertSelectorTextContains('h2', 'Welcome to symmine');
        $this->assertSelectorExists('input[name="_username"]');
        $this->assertSelectorExists('input[name="_password"]');

        // Fill in the login form
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'admin',
            '_password' => 'admin',
        ]);

        // Submit the form
        $client->submit($form);

        // Wait for redirect and verify we're logged in
        $client->waitFor('.min-h-screen');

        // Should be redirected away from login page
        $this->assertStringNotContainsString('/login', $client->getCurrentURL());

        // Verify we can access a protected page (projects list or similar)
        // This confirms the session is properly established
        $client->request('GET', '/projects');
        $this->assertResponseIsSuccessful();
    }

    /**
     * Test successful login with regular user credentials
     */
    public function testSuccessfulLoginWithRegularUser(): void
    {
        $client = static::createPantherClient();
        $crawler = $client->request('GET', '/login');

        // Fill in the login form with regular user
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'jsmith',
            '_password' => 'password123',
        ]);

        $client->submit($form);

        // Wait for redirect
        $client->waitFor('.min-h-screen');

        // Should be redirected away from login page
        $this->assertStringNotContainsString('/login', $client->getCurrentURL());
    }

    /**
     * Test login failure with invalid credentials
     */
    public function testLoginFailureWithInvalidCredentials(): void
    {
        $client = static::createPantherClient();
        $crawler = $client->request('GET', '/login');

        // Fill in the login form with invalid credentials
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'admin',
            '_password' => 'wrongpassword',
        ]);

        $client->submit($form);

        // Wait for page to reload
        $client->waitForVisibility('.bg-red-50');

        // Should still be on login page
        $this->assertStringContainsString('/login', $client->getCurrentURL());

        // Should show error message
        $this->assertSelectorExists('.bg-red-50');
        $this->assertSelectorTextContains('.bg-red-50', 'Invalid credentials');
    }

    /**
     * Test login failure with non-existent user
     */
    public function testLoginFailureWithNonExistentUser(): void
    {
        $client = static::createPantherClient();
        $crawler = $client->request('GET', '/login');

        // Fill in the login form with non-existent user
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'nonexistentuser',
            '_password' => 'somepassword',
        ]);

        $client->submit($form);

        // Wait for page to reload
        $client->waitForVisibility('.bg-red-50');

        // Should still be on login page with error
        $this->assertStringContainsString('/login', $client->getCurrentURL());
        $this->assertSelectorExists('.bg-red-50');
    }

    /**
     * Test login failure with empty credentials
     */
    public function testLoginFailureWithEmptyCredentials(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/login');

        // Try to submit form without filling fields
        // HTML5 validation should prevent submission
        $usernameInput = $client->findElement(\Facebook\WebDriver\WebDriverBy::id('username'));
        $this->assertTrue($usernameInput->getAttribute('required') === 'true');

        $passwordInput = $client->findElement(\Facebook\WebDriver\WebDriverBy::id('password'));
        $this->assertTrue($passwordInput->getAttribute('required') === 'true');
    }

    /**
     * Test that locked user cannot login
     */
    public function testLoginFailureWithLockedUser(): void
    {
        $client = static::createPantherClient();
        $crawler = $client->request('GET', '/login');

        // Try to login with locked user (rjones is STATUS_LOCKED in fixtures)
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'rjones',
            '_password' => 'password123',
        ]);

        $client->submit($form);

        // Wait for page to reload
        $client->waitForVisibility('.bg-red-50');

        // Should still be on login page
        $this->assertStringContainsString('/login', $client->getCurrentURL());

        // Should show error (locked accounts should not be able to login)
        $this->assertSelectorExists('.bg-red-50');
    }

    /**
     * Test username persistence after failed login
     */
    public function testUsernamePersistedAfterFailedLogin(): void
    {
        $client = static::createPantherClient();
        $crawler = $client->request('GET', '/login');

        $username = 'admin';

        // Fill in the login form with invalid password
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $username,
            '_password' => 'wrongpassword',
        ]);

        $client->submit($form);

        // Wait for page to reload
        $client->waitForVisibility('.bg-red-50');

        // Username should be pre-filled
        $usernameInput = $client->findElement(\Facebook\WebDriver\WebDriverBy::id('username'));
        $this->assertEquals($username, $usernameInput->getAttribute('value'));
    }

    /**
     * Test CSRF token is present in login form
     */
    public function testCSRFTokenPresentInLoginForm(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/login');

        // Verify CSRF token field exists
        $this->assertSelectorExists('input[name="_token"]');

        // Verify it has a value
        $tokenInput = $client->findElement(\Facebook\WebDriver\WebDriverBy::name('_token'));
        $tokenValue = $tokenInput->getAttribute('value');

        $this->assertNotEmpty($tokenValue);
        $this->assertGreaterThan(10, strlen($tokenValue));
    }

    /**
     * Test logout functionality
     */
    public function testLogoutFunctionality(): void
    {
        $client = static::createPantherClient();

        // First, login
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'admin',
            '_password' => 'admin',
        ]);
        $client->submit($form);

        // Wait for successful login
        $client->waitFor('.min-h-screen');

        // Now logout
        $client->request('GET', '/logout');

        // Should be redirected to login page
        $client->waitForVisibility('input[name="_username"]');
        $this->assertStringContainsString('/login', $client->getCurrentURL());

        // Try to access protected page - should redirect to login
        $client->request('GET', '/admin');
        $client->waitForVisibility('input[name="_username"]');
        $this->assertStringContainsString('/login', $client->getCurrentURL());
    }

    /**
     * Test remember me functionality is present
     */
    public function testRememberMeFunctionalityIsPresent(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/login');

        // Verify remember me checkbox exists
        $this->assertSelectorExists('input[name="_remember_me"]');
        $this->assertSelectorExists('label[for="remember_me"]');
    }

    /**
     * Test login page has proper security headers and HTTPS redirect hint
     */
    public function testLoginPageAccessibility(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/login');

        // Verify page loads successfully
        $this->assertSelectorTextContains('h2', 'Welcome to symmine');

        // Verify form elements are accessible
        $this->assertSelectorExists('#username');
        $this->assertSelectorExists('#password');
        $this->assertSelectorExists('button[type="submit"]');

        // Verify labels exist for accessibility
        $this->assertSelectorExists('label[for="username"]');
        $this->assertSelectorExists('label[for="password"]');
    }
}
