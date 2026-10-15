<?php

namespace Drupal\Tests\log_watcher\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\log_watcher\Service\ErrorFingerprintService;

/**
 * Tests for ErrorFingerprintService.
 *
 * @group log_watcher
 */
class ErrorFingerprintServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\log_watcher\Service\ErrorFingerprintService
   */
  protected ErrorFingerprintService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->service = new ErrorFingerprintService();
  }

  /**
   * Test that identical errors produce identical fingerprints.
   */
  public function testIdenticalErrorsProduceSameFingerprint() {
    $channel = 'test';
    $severity = 3;
    $message = 'An error occurred in file.php at line 42';
    $context = [];

    $fp1 = $this->service->generate($channel, $severity, $message, $context);
    $fp2 = $this->service->generate($channel, $severity, $message, $context);

    $this->assertEquals($fp1, $fp2, 'Identical errors should produce same fingerprint');
  }

  /**
   * Test that different messages produce different fingerprints.
   */
  public function testDifferentMessagesProduceDifferentFingerprints() {
    $channel = 'test';
    $severity = 3;
    $message1 = 'Error A';
    $message2 = 'Error B';

    $fp1 = $this->service->generate($channel, $severity, $message1, []);
    $fp2 = $this->service->generate($channel, $severity, $message2, []);

    $this->assertNotEquals($fp1, $fp2, 'Different messages should produce different fingerprints');
  }

  /**
   * Test message normalization for UUIDs.
   */
  public function testNormalizesUuids() {
    $channel = 'test';
    $severity = 3;

    $message1 = 'Error for UUID 550e8400-e29b-41d4-a716-446655440000';
    $message2 = 'Error for UUID 6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    // Both should produce same fingerprint because UUIDs are normalized.
    $fp1 = $this->service->generate($channel, $severity, $message1, []);
    $fp2 = $this->service->generate($channel, $severity, $message2, []);

    $this->assertEquals($fp1, $fp2, 'UUIDs should be normalized in fingerprinting');
  }

  /**
   * Test message normalization for numeric IDs.
   */
  public function testNormalizesNumericIds() {
    $channel = 'test';
    $severity = 3;

    $message1 = 'Node 123 not found';
    $message2 = 'Node 456 not found';

    // Both should produce same fingerprint because numeric IDs are normalized.
    $fp1 = $this->service->generate($channel, $severity, $message1, []);
    $fp2 = $this->service->generate($channel, $severity, $message2, []);

    $this->assertEquals($fp1, $fp2, 'Numeric IDs should be normalized in fingerprinting');
  }

  /**
   * Test that severity affects fingerprint.
   */
  public function testSeverityAffectsFingerprint() {
    $channel = 'test';
    $message = 'Error occurred';

    $fp_error = $this->service->generate($channel, 3, $message, []);
    $fp_warning = $this->service->generate($channel, 4, $message, []);

    $this->assertNotEquals($fp_error, $fp_warning, 'Different severity should produce different fingerprints');
  }

  /**
   * Test that channel affects fingerprint.
   */
  public function testChannelAffectsFingerprint() {
    $severity = 3;
    $message = 'Error occurred';

    $fp_php = $this->service->generate('php', $severity, $message, []);
    $fp_cron = $this->service->generate('cron', $severity, $message, []);

    $this->assertNotEquals($fp_php, $fp_cron, 'Different channel should produce different fingerprints');
  }

}

