<?php

namespace Drupal\log_watcher\Drush\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Database\Connection;
use Drupal\log_watcher\Service\ConfigService;
use Drupal\log_watcher\Service\MaintenanceService;
use Drupal\log_watcher\Service\NotificationService;
use Drupal\log_watcher\Repository\ErrorGroupRepository;

/**
 * Drush commands for Log Watcher.
 */
class LogWatcherCommands extends DrushCommands {

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
   * The maintenance service.
   *
   * @var \Drupal\log_watcher\Service\MaintenanceService
   */
  protected MaintenanceService $maintenanceService;

  /**
   * The notification service.
   *
   * @var \Drupal\log_watcher\Service\NotificationService
   */
  protected NotificationService $notificationService;

  /**
   * The error group repository.
   *
   * @var \Drupal\log_watcher\Repository\ErrorGroupRepository
   */
  protected ErrorGroupRepository $groupRepository;

  /**
   * Constructor.
   */
  public function __construct(
    Connection $connection,
    ConfigService $config_service,
    MaintenanceService $maintenance_service,
    NotificationService $notification_service,
    ErrorGroupRepository $group_repository
  ) {
    parent::__construct();
    $this->connection = $connection;
    $this->configService = $config_service;
    $this->maintenanceService = $maintenance_service;
    $this->notificationService = $notification_service;
    $this->groupRepository = $group_repository;
  }

  /**
   * Check and process Log Watcher status.
   *
   * @command log-watcher:check
   * @aliases lwc
   * @description Display current Log Watcher status and group counts.
   */
  public function check() {
    if (!$this->configService->isEnabled()) {
      $this->logger()->warning('Log Watcher is currently disabled.');
      return;
    }

    $active_count = $this->groupRepository->countGroups(['new', 'active']);
    $resolved_count = $this->groupRepository->countGroups('resolved');
    $ignored_count = $this->groupRepository->countGroups('ignored');
    $occurrences = $this->groupRepository->getTotalOccurrences(['new', 'active']);

    $this->io()->writeln("Log Watcher Status:");
    $this->io()->writeln("  Enabled: Yes");
    $this->io()->writeln("  Active Groups: $active_count");
    $this->io()->writeln("  Total Occurrences: $occurrences");
    $this->io()->writeln("  Resolved Groups: $resolved_count");
    $this->io()->writeln("  Ignored Groups: $ignored_count");

    // Show top groups.
    $top_groups = $this->groupRepository->getActiveGroups(NULL, NULL, ['new', 'active'], 5, 0);
    if (!empty($top_groups)) {
      $this->io()->writeln("\nTop 5 Error Groups:");
      foreach ($top_groups as $group) {
        $this->io()->writeln("  - " . substr($group['message'], 0, 60) . " (" . $group['occurrence_count'] . " occurrences)");
      }
    }
  }

  /**
   * Send Log Watcher digest.
   *
   * @command log-watcher:send
   * @aliases lws
   * @description Send email digest manually.
   * @option force Skip time check and send immediately
   */
  public function send($options = ['force' => FALSE]) {
    if (!$this->configService->isEnabled()) {
      $this->logger()->warning('Log Watcher is disabled.');
      return;
    }

    $recipients = $this->configService->getEmailRecipients();
    if (empty($recipients)) {
      $this->logger()->error('No email recipients configured.');
      return;
    }

    try {
      $this->notificationService->sendDigest();
      $this->io()->success('Digest sent successfully.');
    } catch (\Exception $e) {
      $this->logger()->error('Failed to send digest: ' . $e->getMessage());
    }
  }

  /**
   * Run Log Watcher maintenance.
   *
   * @command log-watcher:cleanup
   * @aliases lwcl
   * @description Run data retention and cleanup.
   */
  public function cleanup() {
    if (!$this->configService->isEnabled()) {
      $this->logger()->warning('Log Watcher is disabled.');
      return;
    }

    try {
      $this->maintenanceService->cleanupOldData();
      $this->io()->success('Cleanup completed.');
    } catch (\Exception $e) {
      $this->logger()->error('Cleanup failed: ' . $e->getMessage());
    }
  }

  /**
   * Clear Log Watcher data.
   *
   * @command log-watcher:clear
   * @aliases lwcr
   * @description Clear Log Watcher data.
   * @option status Status to clear (resolved, ignored, or all)
   * @option force Skip confirmation
   */
  public function clear($options = ['status' => 'all', 'force' => FALSE]) {
    $status = $options['status'];
    if (!in_array($status, ['resolved', 'ignored', 'all'])) {
      $this->logger()->error('Invalid status. Use: resolved, ignored, or all');
      return;
    }

    if (!$options['force']) {
      if (!$this->io()->confirm('Are you sure you want to delete all ' . $status . ' Log Watcher data?')) {
        $this->logger()->notice('Cancelled.');
        return;
      }
    }

    try {
      if ($status === 'all') {
        $this->connection->truncate('log_watcher_occurrences')->execute();
        $this->connection->truncate('log_watcher_error_groups')->execute();
        $this->io()->success('All Log Watcher data cleared.');
      } else {
        $group_ids = $this->connection->select('log_watcher_error_groups', 'g')
          ->fields('g', ['id'])
          ->condition('status', $status)
          ->execute()
          ->fetchAllKeyed(0, 0);

        if (!empty($group_ids)) {
          $this->connection->delete('log_watcher_occurrences')
            ->condition('group_id', array_keys($group_ids), 'IN')
            ->execute();

          $this->connection->delete('log_watcher_error_groups')
            ->condition('id', array_keys($group_ids), 'IN')
            ->execute();
        }

        $this->io()->success(ucfirst($status) . ' groups cleared.');
      }
    } catch (\Exception $e) {
      $this->logger()->error('Clear failed: ' . $e->getMessage());
    }
  }

}

