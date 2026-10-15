<?php

namespace Drupal\log_watcher\Service;

use Drupal\Core\Database\Connection;

/**
 * Service for managing error groups.
 */
class ErrorGroupService {

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
   * The fingerprint service.
   *
   * @var \Drupal\log_watcher\Service\ErrorFingerprintService
   */
  protected ErrorFingerprintService $fingerprintService;

  /**
   * Constructor.
   */
  public function __construct(Connection $connection, ErrorFingerprintService $fingerprint_service) {
    $this->connection = $connection;
    $this->fingerprintService = $fingerprint_service;
  }

  /**
   * Find or create an error group and increment its occurrence count.
   *
   * This method is safe for concurrent access and handles race conditions.
   * If two requests try to create the same group simultaneously, they will
   * both increment the same group (only one will be created).
   *
   * @param string $channel
   *   Logger channel.
   * @param int $severity
   *   RFC 5424 severity level.
   * @param string $message
   *   Log message.
   * @param array $context
   *   Log context array.
   *
   * @return array
   *   The error group record array with keys: id, fingerprint, channel, severity,
   *   status, message, occurrence_count, first_seen, last_seen, etc.
   */
  public function findOrCreateAndIncrement(
    string $channel,
    int $severity,
    string $message,
    array $context = []
  ): array {
    $fingerprint = $this->fingerprintService->generate($channel, $severity, $message, $context);
    $now = \Drupal::time()->getRequestTime();

    // Try to find existing group.
    $existing = $this->findByFingerprint($fingerprint);
    if ($existing) {
      // Increment count and update last seen.
      $this->incrementGroup($existing['id'], $message, $context);
      return $this->getGroup($existing['id']);
    }

    // Group doesn't exist. Try to create it, but handle race conditions.
    // If another request creates it first, we'll get a duplicate key exception
    // and should fall back to incrementing the new one.
    try {
      $group_id = $this->createGroup(
        $fingerprint,
        $channel,
        $severity,
        $message,
        $context,
        $now
      );
      return $this->getGroup($group_id);
    } catch (\Exception $e) {
      // Likely a duplicate key exception from concurrent creation.
      // Re-fetch and increment.
      $existing = $this->findByFingerprint($fingerprint);
      if ($existing) {
        $this->incrementGroup($existing['id'], $message, $context);
        return $this->getGroup($existing['id']);
      }
      // If still not found, re-throw the original exception.
      throw $e;
    }
  }

  /**
   * Find an error group by fingerprint.
   */
  public function findByFingerprint(string $fingerprint): ?array {
    $result = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g')
      ->condition('fingerprint', $fingerprint)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Get an error group by ID.
   */
  public function getGroup(int $id): ?array {
    $result = $this->connection->select('log_watcher_error_groups', 'g')
      ->fields('g')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Create a new error group.
   *
   * @return int
   *   The new group ID.
   */
  protected function createGroup(
    string $fingerprint,
    string $channel,
    int $severity,
    string $message,
    array $context,
    int $now
  ): int {
    $normalized_message = $this->extractTitle($message);
    $exception_class = $this->extractExceptionClass($context);
    $file = $this->extractFile($context);
    $line = $this->extractLine($context);

    $group_id = $this->connection->insert('log_watcher_error_groups')
      ->fields([
        'fingerprint' => $fingerprint,
        'channel' => $channel,
        'severity' => $severity,
        'status' => 'new',
        'message' => $normalized_message,
        'occurrence_count' => 1,
        'first_seen' => $now,
        'last_seen' => $now,
        'exception_class' => $exception_class,
        'file' => $file,
        'line' => $line,
        'representative_uri' => $this->extractUri($context),
        'representative_referrer' => $this->extractReferrer($context),
        'representative_uid' => $this->extractUid($context),
        'representative_ip_hash' => $this->hashIp($context),
        'representative_context' => $this->sanitizeContext($context),
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    return $group_id;
  }

  /**
   * Increment an existing error group's occurrence count.
   */
  protected function incrementGroup(int $group_id, string $message, array $context): void {
    $now = \Drupal::time()->getRequestTime();

    // Update the group: increment count, update last_seen, and update representative data.
    $update = $this->connection->update('log_watcher_error_groups');
    $update->fields([
        'last_seen' => $now,
        'representative_uri' => $this->extractUri($context),
        'representative_referrer' => $this->extractReferrer($context),
        'representative_uid' => $this->extractUid($context),
        'representative_ip_hash' => $this->hashIp($context),
        'representative_context' => $this->sanitizeContext($context),
        'updated' => $now,
      ])
      ->condition('id', $group_id);
    $update->expression('occurrence_count', 'occurrence_count + :increment', [':increment' => 1]);
    $update->execute();
  }

  /**
   * Extract a title/short message from the full message.
   */
  protected function extractTitle(string $message): string {
    // Truncate to 255 characters to fit in the message field.
    $title = substr($message, 0, 255);
    // Try to extract just the first line or sentence.
    if (strpos($title, "\n") !== FALSE) {
      $title = substr($title, 0, strpos($title, "\n"));
    }
    if (strpos($title, '. ') !== FALSE) {
      $title = substr($title, 0, strpos($title, '. ') + 1);
    }
    return trim($title);
  }

  /**
   * Extract exception class from context.
   */
  protected function extractExceptionClass(array $context): ?string {
    if (isset($context['exception'])) {
      if ($context['exception'] instanceof \Throwable) {
        return get_class($context['exception']);
      }
    }

    if (!empty($context['error_class'])) {
      return (string) $context['error_class'];
    }

    return NULL;
  }

  /**
   * Extract file from context.
   */
  protected function extractFile(array $context): ?string {
    if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
      $file = $context['exception']->getFile();
      return basename($file);
    }

    if (!empty($context['file'])) {
      return basename((string) $context['file']);
    }

    return NULL;
  }

  /**
   * Extract line number from context.
   */
  protected function extractLine(array $context): ?int {
    if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
      return $context['exception']->getLine();
    }

    if (!empty($context['line'])) {
      return (int) $context['line'];
    }

    return NULL;
  }

  /**
   * Extract request URI.
   */
  protected function extractUri(array $context): ?string {
    if (!empty($context['request_uri'])) {
      $uri = (string) $context['request_uri'];
      // Strip off query parameters for privacy.
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
      // Hash the IP for privacy - we don't need to store it plainly.
      return hash('sha256', (string) $ip);
    }

    return NULL;
  }

  /**
   * Sanitize and serialize context array, removing sensitive data.
   */
  protected function sanitizeContext(array $context): ?string {
    $sanitized = $this->sanitizeContextArray($context, 0);

    if (!empty($sanitized)) {
      // Limit size to prevent unbounded storage.
      $serialized = serialize($sanitized);
      if (strlen($serialized) > 16384) {
        // Truncate if too large.
        $serialized = substr($serialized, 0, 16384);
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

}




