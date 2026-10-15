<?php

namespace Drupal\log_watcher\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for accessing Log Watcher configuration.
 */
class ConfigService {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Check if Log Watcher is enabled.
   */
  public function isEnabled(): bool {
    return (bool) $this->getConfig('enabled');
  }

  /**
   * Get monitored severity levels as RFC 5424 integers.
   *
   * @return array
   *   Array of severity integers.
   */
  public function getMonitoredSeverities(): array {
    $severities = $this->getConfig('severity_levels');
    return is_array($severities) ? $severities : [];
  }

  /**
   * Get email frequency.
   *
   * @return string
   *   Frequency identifier: 'every_run', 'daily', 'weekly', etc.
   */
  public function getEmailFrequency(): string {
    return $this->getConfig('email_frequency') ?? 'weekly';
  }

  /**
   * Get configured email recipients.
   *
   * @return array
   *   Array of valid email addresses.
   */
  public function getEmailRecipients(): array {
    $recipients_text = $this->getConfig('email_recipients') ?? '';
    $recipients = [];
    if ($recipients_text) {
      $lines = array_filter(array_map('trim', explode("\n", $recipients_text)));
      foreach ($lines as $line) {
        if (\Drupal::service('email.validator')->isValid($line)) {
          $recipients[] = $line;
        }
      }
    }
    return $recipients;
  }

  /**
   * Check if digest should be sent when no errors detected.
   */
  public function sendNoErrorDigest(): bool {
    return (bool) $this->getConfig('send_no_error_digest');
  }

  /**
   * Get email subject prefix.
   */
  public function getEmailSubjectPrefix(): string {
    return $this->getConfig('email_subject_prefix') ?? 'Log Watcher Report';
  }

  /**
   * Get maximum groups to include per email.
   */
  public function getMaxGroupsPerEmail(): int {
    $max = $this->getConfig('max_groups_per_email');
    return $max ? (int) $max : 10;
  }

  /**
   * Get maximum active error groups to maintain.
   */
  public function getMaxActiveGroups(): int {
    $max = $this->getConfig('max_active_groups');
    return $max ? (int) $max : 1000;
  }

  /**
   * Get maximum recent occurrences per group.
   */
  public function getMaxOccurrencesPerGroup(): int {
    $max = $this->getConfig('max_occurrences_per_group');
    return $max ? (int) $max : 50;
  }

  /**
   * Get retention period for active groups in days.
   */
  public function getRetentionDays(): int {
    $days = $this->getConfig('retention_days');
    return $days ? (int) $days : 90;
  }

  /**
   * Get retention period for resolved groups in days.
   */
  public function getResolvedRetentionDays(): int {
    $days = $this->getConfig('resolved_retention_days');
    return $days ? (int) $days : 30;
  }

  /**
   * Get retention period for ignored groups in days.
   */
  public function getIgnoredRetentionDays(): int {
    $days = $this->getConfig('ignored_retention_days');
    return $days ? (int) $days : 14;
  }

  /**
   * Get a configuration value.
   */
  protected function getConfig(string $key) {
    return $this->configFactory->get('log_watcher.settings')->get($key);
  }

}

