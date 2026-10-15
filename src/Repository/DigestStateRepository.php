<?php

namespace Drupal\log_watcher\Repository;

use Drupal\Core\Database\Connection;

/**
 * Repository for accessing digest send state.
 */
class DigestStateRepository {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $connection;

  /**
   * Constructor.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * Get the current digest state.
   */
  public function getState(): ?array {
    $result = $this->connection->select('log_watcher_digest_state', 'd')
      ->fields('d')
      ->condition('id', 1)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Update last sent timestamp.
   */
  public function recordSent(?string $subject = NULL, ?string $body = NULL, ?string $body_html = NULL): void {
    $now = \Drupal::time()->getRequestTime();
    $fields = [
      'last_sent' => $now,
      'last_checked' => $now,
      'failed_attempts' => 0,
      'last_failure_message' => NULL,
    ];

    $schema = $this->connection->schema();
    if ($schema->fieldExists('log_watcher_digest_state', 'last_subject')) {
      $fields['last_subject'] = $subject !== NULL ? substr($subject, 0, 255) : NULL;
    }
    if ($schema->fieldExists('log_watcher_digest_state', 'last_body')) {
      $fields['last_body'] = $body;
    }
    if ($schema->fieldExists('log_watcher_digest_state', 'last_body_html')) {
      $fields['last_body_html'] = $body_html;
    }

    $this->connection->update('log_watcher_digest_state')
      ->fields($fields)
      ->condition('id', 1)
      ->execute();
  }

  /**
   * Record a send failure.
   */
  public function recordFailure(string $message): void {
    $now = \Drupal::time()->getRequestTime();
    $current = $this->getState();
    $failed_attempts = ($current['failed_attempts'] ?? 0) + 1;

    $this->connection->update('log_watcher_digest_state')
      ->fields([
        'last_checked' => $now,
        'failed_attempts' => $failed_attempts,
        'last_failure_message' => substr($message, 0, 1024),
      ])
      ->condition('id', 1)
      ->execute();
  }

}

