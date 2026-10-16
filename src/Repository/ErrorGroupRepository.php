<?php

namespace Drupal\log_watcher\Repository;

use Drupal\Core\Database\Connection;

/**
 * Repository for accessing error groups.
 */
class ErrorGroupRepository {

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
   * Get all active groups for reporting.
   */
  public function getActiveGroups(?string $channel = NULL, ?int $severity = NULL, string|array|null $status = NULL, int $limit = 100, int $offset = 0, ?string $search_text = NULL, string $count_order = 'DESC', bool $prioritize_severity = FALSE): array {
    $query = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g');

    if ($channel) {
      $query->condition('channel', $channel);
    }

    if ($severity !== NULL) {
      $query->condition('severity', $severity);
    }

    if (!empty($search_text)) {
      $query->condition('message', '%' . $this->connection->escapeLike($search_text) . '%', 'LIKE');
    }

    if ($status) {
      if (is_array($status)) {
        $query->condition('status', $status, 'IN');
      } else {
        $query->condition('status', $status);
      }
    } else {
      // Default: get active/new groups.
      $query->condition('status', ['new', 'active'], 'IN');
    }

    $count_order = strtoupper($count_order) === 'ASC' ? 'ASC' : 'DESC';

    if ($prioritize_severity) {
      // RFC 5424 uses lower numbers for more severe events. Sort severity
      // before frequency so informational/debug noise cannot displace more
      // actionable warnings and errors from the report.
      $query->orderBy('severity', 'ASC');
    }

    $query->orderBy('occurrence_count', $count_order)
      ->orderBy('last_seen', 'DESC');

    if ($limit > 0) {
      $query->range($offset, $limit);
    }

    return array_values(array_map(static fn ($row) => (array) $row, $query->execute()->fetchAllAssoc('id')));
  }

  /**
   * Get groups by status.
   */
  public function getGroupsByStatus(string $status, int $limit = 100, int $offset = 0): array {
    return $this->getActiveGroups(NULL, NULL, $status, $limit, $offset);
  }

  /**
   * Get groups ordered by various criteria.
   */
  public function getGroupsOrderedBy(
    string $order_by = 'occurrence_count',
    string $order = 'DESC',
    string|array|null $status = NULL,
    int $limit = 100,
    int $offset = 0
  ): array {
    $valid_order_by = ['occurrence_count', 'last_seen', 'first_seen', 'severity', 'created'];
    $valid_order = ['ASC', 'DESC'];

    $order_by = in_array($order_by, $valid_order_by) ? $order_by : 'occurrence_count';
    $order = in_array(strtoupper($order), $valid_order) ? strtoupper($order) : 'DESC';

    $query = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g');

    if ($status) {
      if (is_array($status)) {
        $query->condition('status', $status, 'IN');
      } else {
        $query->condition('status', $status);
      }
    }

    $query->orderBy($order_by, $order);

    if ($limit > 0) {
      $query->range($offset, $limit);
    }

    return array_values(array_map(static fn ($row) => (array) $row, $query->execute()->fetchAllAssoc('id')));
  }

  /**
   * Get total count of groups.
   */
  public function countGroups(string|array|null $status = NULL, ?string $channel = NULL): int {
    $query = $this->connection->select('log_watcher_error_groups', 'g');

    if ($status) {
      if (is_array($status)) {
        $query->condition('status', $status, 'IN');
      } else {
        $query->condition('status', $status);
      }
    }

    if ($channel) {
      $query->condition('channel', $channel);
    }

    $query = $query->countQuery();

    return (int) $query->execute()->fetchField();
  }

  /**
   * Get groups grouped by severity with counts.
   */
  public function getGroupCountsBySeverity(string|array|null $status = NULL): array {
    $query = $this->connection->select('log_watcher_error_groups', 'g');
    $query->fields('g', ['severity']);
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('severity');
    $query->orderBy('severity', 'ASC');

    if ($status) {
      if (is_array($status)) {
        $query->condition('status', $status, 'IN');
      } else {
        $query->condition('status', $status);
      }
    }

    $results = array_map(static fn ($row) => (array) $row, $query->execute()->fetchAll());

    $counts = [];
    foreach ($results as $row) {
      $counts[$row['severity']] = $row['count'];
    }

    return $counts;
  }

  /**
   * Search for groups by message text.
   */
  public function searchGroups(string $search_text, int $limit = 50): array {
    $query = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g')
      ->condition('message', '%' . $this->connection->escapeLike($search_text) . '%', 'LIKE')
      ->orderBy('occurrence_count', 'DESC')
      ->range(0, $limit);

    return array_values(array_map(static fn ($row) => (array) $row, $query->execute()->fetchAllAssoc('id')));
  }

  /**
   * Get new groups (created within last N hours).
   */
  public function getNewGroups(int $hours = 24, int $limit = 50): array {
    $cutoff = \Drupal::time()->getRequestTime() - ($hours * 3600);

    $query = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g')
      ->condition('created', $cutoff, '>')
      ->orderBy('created', 'DESC')
      ->range(0, $limit);

    return array_values(array_map(static fn ($row) => (array) $row, $query->execute()->fetchAllAssoc('id')));
  }

  /**
   * Update a group's status.
   */
  public function updateStatus(int $group_id, string $status): bool {
    $result = $this->connection->update('log_watcher_error_groups')
      ->fields([
        'status' => $status,
        'status_updated' => \Drupal::time()->getRequestTime(),
        'updated' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('id', $group_id)
      ->execute();

    return $result > 0;
  }

  /**
   * Delete a group.
   */
  public function delete(int $group_id): bool {
    // First delete occurrences.
    $this->connection->delete('log_watcher_occurrences')
      ->condition('group_id', $group_id)
      ->execute();

    // Then delete group.
    $result = $this->connection->delete('log_watcher_error_groups')
      ->condition('id', $group_id)
      ->execute();

    return $result > 0;
  }

  /**
   * Delete all groups in a given status.
   */
  public function deleteGroupsByStatus(string $status): int {
    $group_ids = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g', ['id'])
      ->condition('status', $status)
      ->execute()
      ->fetchCol();

    if (empty($group_ids)) {
      return 0;
    }

    $this->connection->delete('log_watcher_occurrences')
      ->condition('group_id', $group_ids, 'IN')
      ->execute();

    return (int) $this->connection->delete('log_watcher_error_groups')
      ->condition('id', $group_ids, 'IN')
      ->execute();
  }

  /**
   * Get total occurrence count across all groups.
   */
  public function getTotalOccurrences(string|array|null $status = NULL): int {
    $query = $this->connection->select('log_watcher_error_groups', 'g');

    $query->addExpression('SUM(occurrence_count)', 'total');

    if ($status) {
      if (is_array($status)) {
        $query->condition('status', $status, 'IN');
      } else {
        $query->condition('status', $status);
      }
    }

    $result = $query->execute()->fetchAssoc();
    return $result['total'] ? (int) $result['total'] : 0;
  }

  /**
   * Get group by ID.
   */
  public function getGroupById(int $id): ?array {
    $result = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

}




