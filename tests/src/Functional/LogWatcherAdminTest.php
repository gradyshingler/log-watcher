<?php

namespace Drupal\Tests\log_watcher\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for Log Watcher admin UI.
 *
 * @group log_watcher
 */
class LogWatcherAdminTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['log_watcher'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([
      'administer log watcher',
      'view log watcher reports',
      'manage log watcher groups',
      'delete log watcher data',
      'access administration pages',
    ]);
  }

  /**
   * Test settings form is accessible.
   */
  public function testSettingsFormAccessible() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/system/log-watcher');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('monitoring[enabled]');
  }

  /**
   * Test settings form validation - no severity selected.
   */
  public function testSettingsFormValidation() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/system/log-watcher');

    // Try to enable without selecting any severity.
    $this->submitForm([
      'monitoring[enabled]' => TRUE,
      'monitoring[severity_levels][]' => [],
      'email[email_recipients]' => 'test@example.com',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('At least one severity level must be selected');
  }

  /**
   * Test settings form validation - invalid email.
   */
  public function testSettingsFormInvalidEmail() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/system/log-watcher');

    $this->submitForm([
      'monitoring[enabled]' => TRUE,
      'monitoring[severity_levels][3]' => TRUE,
      'email[email_recipients]' => 'invalid-email',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('Invalid email address');
  }

  /**
   * Test dashboard is accessible to users with permission.
   */
  public function testDashboardAccessible() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/reports/log-watcher');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Test dashboard denies access without permission.
   */
  public function testDashboardAccessDenied() {
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/reports/log-watcher');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Test maintenance page is accessible.
   */
  public function testMaintenancePageAccessible() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/reports/log-watcher/maintenance');
    $this->assertSession()->statusCodeEquals(200);
  }

}

