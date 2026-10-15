<?php

namespace Drupal\log_watcher\Repository;

use Drupal\Core\Database\Connection;

/**
 * Repository for accessing error occurrences.
 */
class OccurrenceRepository {

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
   * Get recent occurrences for a group.
   */
  public function getRecentByGroup(int $group_id, int $limit = 50): array {
    $occurrences = $this->connection->select('log_watcher_occurrences', 'o')
      ->fields('o')
      ->condition('group_id', $group_id)
      ->orderBy('occurred_at', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);

    return array_values($occurrences);
  }

  /**
   * Get occurrence by ID.
   */
  public function getById(int $id): ?array {
    $result = $this->connection->select('log_watcher_occurrences', 'o')
      ->fields('o')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Delete all occurrences for a group.
   */
  public function deleteByGroup(int $group_id): int {
    return $this->connection->delete('log_watcher_occurrences')
      ->condition('group_id', $group_id)
      ->execute();
  }

}

