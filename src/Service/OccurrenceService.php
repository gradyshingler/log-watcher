<?php

namespace Drupal\log_watcher\Service;

use Drupal\Core\Database\Connection;

/**
 * Service for managing error occurrences.
 */
class OccurrenceService {

  /**
   * Maximum depth to recurse while sanitizing nested context arrays.
   */
  private const MAX_CONTEXT_DEPTH = 8;

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
   * Record an occurrence of an error group.
   *
   * This method records a single occurrence, then checks if we need to
   * prune old occurrences to stay within the bounded limit.
   *
   * @param int $group_id
   *   The error group ID.
   * @param string $message
   *   Full error message.
   * @param array $context
   *   Log context array.
   * @param int $watchdog_id
   *   Optional watchdog log ID if one exists.
   * @param int $max_occurrences
   *   Maximum occurrences to retain for this group.
   */
  public function recordOccurrence(
    int $group_id,
    string $message,
    array $context,
    ?int $watchdog_id = NULL,
    int $max_occurrences = 50
  ): void {
    $now = \Drupal::time()->getRequestTime();

    // Insert the occurrence.
    $this->connection->insert('log_watcher_occurrences')
      ->fields([
        'group_id' => $group_id,
        'watchdog_id' => $watchdog_id,
        'occurred_at' => $now,
        'message' => substr($message, 0, 2048),
        'context' => $this->serializeContext($context),
        'uri' => $this->extractUri($context),
        'referrer' => $this->extractReferrer($context),
        'uid' => $this->extractUid($context),
        'ip_hash' => $this->hashIp($context),
      ])
      ->execute();

    // Prune if we've exceeded the max occurrences.
    $count = $this->connection->select('log_watcher_occurrences', 'o')
      ->condition('group_id', $group_id)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count > $max_occurrences) {
      // Delete oldest occurrences to maintain the bounded limit.
      $oldest = $this->connection->select('log_watcher_occurrences', 'o')
        ->fields('o', ['id'])
        ->condition('group_id', $group_id)
        ->orderBy('occurred_at', 'ASC')
        ->range(0, $count - $max_occurrences)
        ->execute()
        ->fetchAllAssoc('id');

      if (!empty($oldest)) {
        $this->connection->delete('log_watcher_occurrences')
          ->condition('id', array_keys($oldest), 'IN')
          ->execute();
      }
    }
  }

  /**
   * Get recent occurrences for a group.
   *
   * @param int $group_id
   *   The error group ID.
   * @param int $limit
   *   Maximum number of occurrences to return.
   *
   * @return array
   *   Array of occurrence records, most recent first.
   */
  public function getRecentOccurrences(int $group_id, int $limit = 10): array {
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
   * Delete all occurrences for a group.
   */
  public function deleteOccurrencesForGroup(int $group_id): int {
    return $this->connection->delete('log_watcher_occurrences')
      ->condition('group_id', $group_id)
      ->execute();
  }

  /**
   * Serialize context array for storage.
   */
  protected function serializeContext(array $context): ?string {
    $sanitized = $this->sanitizeContextArray($context, 0);

    if (!empty($sanitized)) {
      $serialized = serialize($sanitized);
      if (strlen($serialized) > 8192) {
        $serialized = substr($serialized, 0, 8192);
      }
      return $serialized;
    }

    return NULL;
  }

  /**
   * Recursively sanitize a context array.
   */
  protected function sanitizeContextArray(array $context, int $depth = 0): array {
    if ($depth >= self::MAX_CONTEXT_DEPTH) {
      return ['__truncated__' => TRUE];
    }

    $sensitive_keys = [
      'password', 'pass', 'secret', 'token', 'session', 'cookie',
      'authorization', 'authentication', 'api_key', 'private_key',
    ];

    $sanitized = [];
    foreach ($context as $key => $value) {
      $key_lower = strtolower((string) $key);
      foreach ($sensitive_keys as $sensitive) {
        if (strpos($key_lower, $sensitive) !== FALSE) {
          continue 2;
        }
      }

      $sanitized[$key] = $this->sanitizeContextValue($value, $depth + 1);
    }

    return $sanitized;
  }

  /**
   * Sanitize a single context value recursively.
   */
  protected function sanitizeContextValue(mixed $value, int $depth = 0): mixed {
    if ($depth >= self::MAX_CONTEXT_DEPTH) {
      return ['__truncated__' => TRUE];
    }

    if ($value instanceof \Throwable) {
      return [
        'class' => get_class($value),
        'message' => $value->getMessage(),
        'file' => basename($value->getFile()),
        'line' => $value->getLine(),
        'code' => $value->getCode(),
      ];
    }

    if (is_array($value)) {
      return $this->sanitizeContextArray($value, $depth + 1);
    }

    if (is_object($value) || is_resource($value)) {
      return NULL;
    }

    if (is_string($value) && strlen($value) > 4096) {
      return substr($value, 0, 4096);
    }

    return $value;
  }

  /**
   * Extract request URI.
   */
  protected function extractUri(array $context): ?string {
    if (!empty($context['request_uri'])) {
      $uri = (string) $context['request_uri'];
      if (strpos($uri, '?') !== FALSE) {
        $uri = substr($uri, 0, strpos($uri, '?'));
      }
      return substr($uri, 0, 2048);
    }

    return NULL;
  }

  /**
   * Extract HTTP referrer.
   */
  protected function extractReferrer(array $context): ?string {
    if (!empty($context['referrer'])) {
      return substr((string) $context['referrer'], 0, 2048);
    }

    if (!empty($context['http_referer'])) {
      return substr((string) $context['http_referer'], 0, 2048);
    }

    return NULL;
  }

  /**
   * Extract user ID.
   */
  protected function extractUid(array $context): ?int {
    if (!empty($context['uid'])) {
      return (int) $context['uid'];
    }

    if (!empty($context['user_id'])) {
      return (int) $context['user_id'];
    }

    return NULL;
  }

  /**
   * Create a privacy-safe hash of IP address.
   */
  protected function hashIp(array $context): ?string {
    $ip = NULL;

    if (!empty($context['ip'])) {
      $ip = $context['ip'];
    } elseif (!empty($context['remote_addr'])) {
      $ip = $context['remote_addr'];
    }

    if ($ip && $ip !== 'unknown') {
      return hash('sha256', (string) $ip);
    }

    return NULL;
  }

}



