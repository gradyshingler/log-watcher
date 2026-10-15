<?php

namespace Drupal\log_watcher\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Service for maintenance tasks and digest scheduling.
 */
class MaintenanceService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $connection;

  /**
   * The config service.
   *
   * @var \Drupal\log_watcher\Service\ConfigService
   */
  protected ConfigService $configService;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The notification service.
   */
  protected NotificationService $notificationService;

  /**
   * Constructor.
   */
  public function __construct(
    Connection $connection,
    ConfigService $config_service,
    NotificationService $notification_service,
    LoggerChannelInterface $logger
  ) {
    $this->connection = $connection;
    $this->configService = $config_service;
    $this->notificationService = $notification_service;
    $this->logger = $logger;
  }

  /**
   * Process digest scheduling and send if due.
   *
   * This is called from cron and determines whether a digest should be sent
   * based on the configured frequency and last send time.
   */
  public function processDigestSchedule(): void {
    if (!$this->configService->isEnabled()) {
      return;
    }

    // Check if digest is due.
    if (!$this->isDigestDue()) {
      return;
    }

    // Digest is due - send it.
    try {
      $this->notificationService->sendDigest();
    } catch (\Exception $e) {
      $this->recordDigestFailure($e->getMessage());
      $this->logger->error('Failed to send Log Watcher digest: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Determine if a digest is due based on configuration and last send time.
   */
  protected function isDigestDue(): bool {
    $frequency = $this->configService->getEmailFrequency();
    $state = $this->getDigestState();

    if ($state === NULL) {
      // No state record; should never happen but be safe.
      return TRUE;
    }

    $now = \Drupal::time()->getRequestTime();
    $last_sent = $state['last_sent'] ?? NULL;

    // If never sent, always send on first cron.
    if ($last_sent === NULL) {
      return TRUE;
    }

    $interval = $this->frequencyToSeconds($frequency);
    if ($interval === NULL) {
      // Invalid frequency, don't send.
      return FALSE;
    }

    return ($now - $last_sent) >= $interval;
  }

  /**
   * Get digest scheduling/status information for administrative displays.
   */
  public function getDigestStatus(): array {
    $state = $this->getDigestState() ?? [];
    $now = \Drupal::time()->getRequestTime();
    $frequency = $this->configService->getEmailFrequency();
    $last_sent = $state['last_sent'] ?? NULL;
    $next_due = $this->calculateNextDueTimestamp($frequency, $last_sent, $now);

    return [
      'state' => $state,
      'frequency' => $frequency,
      'last_sent' => $last_sent,
      'next_due' => $next_due,
      'due_now' => $next_due !== NULL && $next_due <= $now,
      'recipient_count' => count($this->configService->getEmailRecipients()),
    ];
  }

  /**
   * Send the digest immediately using the current saved configuration.
   */
  public function sendDigestNow(): array {
    try {
      return $this->notificationService->sendDigest(TRUE);
    }
    catch (\Exception $e) {
      $this->recordDigestFailure($e->getMessage());
      $this->logger->error('Failed to send Log Watcher digest manually: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Convert frequency string to seconds.
   */
  protected function frequencyToSeconds(string $frequency): ?int {
    return match($frequency) {
      'every_run' => 0,
      'daily' => 86400,
      'every_2_days' => 172800,
      'weekly' => 604800,
      'biweekly' => 1209600,
      'monthly' => 2592000,
      'every_friday' => $this->secondsUntilFriday(),
      default => NULL,
    };
  }

  /**
   * Calculate seconds until next Friday.
   */
  protected function secondsUntilFriday(): int {
    $now = \Drupal::time()->getRequestTime();
    $today = date('w', $now); // 0=Sunday, 5=Friday
    $target = 5; // Friday

    if ($today <= $target) {
      $days_until = $target - $today;
      if ($days_until === 0) {
        // It's Friday now; send today if in first run, otherwise next Friday.
        $days_until = 7;
      }
    } else {
      $days_until = 7 - ($today - $target);
    }

    return $days_until * 86400;
  }

  /**
   * Calculate the next due timestamp based on frequency and last sent time.
   */
  protected function calculateNextDueTimestamp(string $frequency, ?int $last_sent, int $now): ?int {
    if ($frequency === 'every_run') {
      return $last_sent ?: $now;
    }

    if ($frequency === 'every_friday') {
      $reference = $last_sent ?: $now;
      $day_of_week = (int) date('w', $reference);
      $days_until = 5 - $day_of_week;
      if ($days_until <= 0) {
        $days_until += 7;
      }

      return strtotime('midnight', $reference) + ($days_until * 86400);
    }

    $interval = $this->frequencyToSeconds($frequency);
    if ($interval === NULL) {
      return NULL;
    }

    return $last_sent ? $last_sent + $interval : $now;
  }

  /**
   * Get the current digest state record.
   */
  protected function getDigestState(): ?array {
    $state = $this->connection->select('log_watcher_digest_state', 'd')
      ->fields('d')
      ->condition('id', 1)
      ->execute()
      ->fetchAssoc();

    return $state ?: NULL;
  }


  /**
   * Record that a digest send failed.
   */
  protected function recordDigestFailure(string $message): void {
    $now = \Drupal::time()->getRequestTime();
    $current_state = $this->getDigestState();
    $failed_attempts = ($current_state['failed_attempts'] ?? 0) + 1;

    $this->connection->update('log_watcher_digest_state')
      ->fields([
        'last_checked' => $now,
        'failed_attempts' => $failed_attempts,
        'last_failure_message' => substr($message, 0, 1024),
      ])
      ->condition('id', 1)
      ->execute();
  }

  /**
   * Clean up old error groups based on retention policies.
   *
   * This should be called periodically from cron to keep the tables bounded.
   */
  public function cleanupOldData(): void {
    if (!$this->configService->isEnabled()) {
      return;
    }

    $now = \Drupal::time()->getRequestTime();

    // Delete old active groups beyond retention period.
    $retention_seconds = $this->configService->getRetentionDays() * 86400;
    $cutoff = $now - $retention_seconds;

    $active_ids = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g', ['id'])
      ->condition('status', ['new', 'active'], 'IN')
      ->condition('last_seen', $cutoff, '<')
      ->execute()
      ->fetchAllKeyed(0, 0);

    if (!empty($active_ids)) {
      $this->deleteGroups(array_keys($active_ids));
    }

    // Delete old resolved groups beyond their retention period.
    $resolved_retention = $this->configService->getResolvedRetentionDays() * 86400;
    $resolved_cutoff = $now - $resolved_retention;

    $resolved_ids = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g', ['id'])
      ->condition('status', 'resolved')
      ->condition('status_updated', $resolved_cutoff, '<')
      ->execute()
      ->fetchAllKeyed(0, 0);

    if (!empty($resolved_ids)) {
      $this->deleteGroups(array_keys($resolved_ids));
    }

    // Delete old ignored groups beyond their retention period.
    $ignored_retention = $this->configService->getIgnoredRetentionDays() * 86400;
    $ignored_cutoff = $now - $ignored_retention;

    $ignored_ids = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g', ['id'])
      ->condition('status', 'ignored')
      ->condition('status_updated', $ignored_cutoff, '<')
      ->execute()
      ->fetchAllKeyed(0, 0);

    if (!empty($ignored_ids)) {
      $this->deleteGroups(array_keys($ignored_ids));
    }

    // If we're over the max active groups limit, delete oldest ones.
    $max_groups = $this->configService->getMaxActiveGroups();
    $count = $this->connection->select('log_watcher_error_groups', 'g')
      ->condition('status', ['new', 'active'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count > $max_groups) {
      $excess = $count - $max_groups;
      $oldest_ids = $this->connection->select('log_watcher_error_groups', 'g')
        ->fields('g', ['id'])
        ->condition('status', ['new', 'active'], 'IN')
        ->orderBy('last_seen', 'ASC')
        ->range(0, $excess)
        ->execute()
        ->fetchAllKeyed(0, 0);

      if (!empty($oldest_ids)) {
        $this->deleteGroups(array_keys($oldest_ids));
      }
    }
  }

  /**
   * Delete error groups and their occurrences.
   *
   * @param array $group_ids
   *   Array of group IDs to delete.
   */
  protected function deleteGroups(array $group_ids): void {
    if (empty($group_ids)) {
      return;
    }

    // Delete occurrences first.
    $this->connection->delete('log_watcher_occurrences')
      ->condition('group_id', $group_ids, 'IN')
      ->execute();

    // Delete groups.
    $this->connection->delete('log_watcher_error_groups')
      ->condition('id', $group_ids, 'IN')
      ->execute();
  }

}


