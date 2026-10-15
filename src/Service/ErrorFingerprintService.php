<?php

namespace Drupal\log_watcher\Service;

/**
 * Service for generating deterministic error fingerprints.
 *
 * Error Grouping Strategy Documentation:
 *
 * This service generates a stable fingerprint for error grouping. The strategy
 * is conservative to avoid incorrectly combining unrelated errors.
 *
 * FINGERPRINT COMPONENTS:
 *
 * 1. Channel/Type (always used):
 *    - Drupal logger channel, e.g., 'php', 'cron', 'custom_module'
 *
 * 2. Severity (always used):
 *    - RFC 5424 level to ensure different severities aren't grouped
 *
 * 3. Exception Class (when available):
 *    - If an exception is thrown, its class name is a stable identifier
 *    - Takes priority over message normalization
 *
 * 4. Normalized Message:
 *    - Message text with common variable values replaced
 *    - Normalization is conservative to avoid over-matching
 *
 * 5. File & Line (when available):
 *    - Source file and line number from exception/error
 *    - Prefer this over request-specific details
 *    - Greatly improves grouping accuracy
 *
 * 6. Stack Trace Hash (when available):
 *    - Hash of the complete stack trace for additional accuracy
 *    - Helps distinguish similar errors from different code paths
 *
 * NORMALIZATION EXAMPLES:
 *
 * - Numeric IDs: "Node 12345" -> "Node {ID}"
 * - UUIDs: Various UUID patterns -> "{UUID}"
 * - Timestamps: Unix timestamps, ISO 8601 -> "{TIMESTAMP}"
 * - URLs with params: Remove query parameters but keep path
 * - Email addresses: "user@example.com" -> "{EMAIL}"
 *
 * Conservative approach: Only replace obvious patterns, not aggressive fuzzy matching.
 *
 * PRIORITY ORDER:
 *
 * 1. If exception class + file + line available: use these for strong fingerprint
 * 2. If normalized message is available: use it
 * 3. Fall back to full message if unavailable
 *
 * This ensures stack trace information is used when available, which is more
 * reliable than message text that may contain dynamic values.
 */
class ErrorFingerprintService {

  /**
   * Generate a deterministic fingerprint for error grouping.
   *
   * @param string $channel
   *   Drupal logger channel.
   * @param int $severity
   *   RFC 5424 severity level.
   * @param string $message
   *   Log message (may contain dynamic values).
   * @param array $context
   *   Log context array from Drupal logging.
   *
   * @return string
   *   64-character hex fingerprint.
   */
  public function generate(string $channel, int $severity, string $message, array $context = []): string {
    $fingerprint_parts = [];

    // 1. Channel and severity are always part of fingerprint.
    $fingerprint_parts[] = $channel;
    $fingerprint_parts[] = (string) $severity;

    // 2. Extract and use exception information if available.
    $exception_class = $this->extractExceptionClass($context);
    if ($exception_class) {
      $fingerprint_parts[] = 'exception:' . $exception_class;

      // Use file and line from exception if available.
      $file = $this->extractFile($context);
      $line = $this->extractLine($context);
      if ($file && $line) {
        $fingerprint_parts[] = $file . ':' . $line;
      }
    }

    // 3. Normalize and include message.
    $normalized_message = $this->normalizeMessage($message);
    $fingerprint_parts[] = $normalized_message;

    // 4. If there's a stack trace, include a hash of it for extra accuracy.
    if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
      $trace_hash = $this->hashStackTrace($context['exception']);
      $fingerprint_parts[] = 'trace:' . $trace_hash;
    }

    // Combine all parts and hash to 64 characters.
    $combined = implode('|', $fingerprint_parts);
    return hash('sha256', $combined);
  }

  /**
   * Normalize a message by replacing common dynamic values.
   *
   * @param string $message
   *   Raw message text.
   *
   * @return string
   *   Normalized message.
   */
  protected function normalizeMessage(string $message): string {
    $normalized = $message;

    // Replace numeric IDs (but be conservative - only obvious patterns).
    // Node 12345 -> Node {ID}, User 999 -> User {ID}
    $normalized = preg_replace('/\b(node|user|entity|id)[\s:]+\d+\b/i', '$1 {ID}', $normalized);

    // Replace UUIDs in various formats.
    $uuid_pattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    $normalized = preg_replace("/{$uuid_pattern}/i", '{UUID}', $normalized);

    // Replace Unix timestamps (10-13 digits).
    $normalized = preg_replace('/\b\d{10,13}\b/', '{TIMESTAMP}', $normalized);

    // Replace ISO 8601 timestamps.
    $iso_pattern = '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}';
    $normalized = preg_replace("/{$iso_pattern}/", '{TIMESTAMP}', $normalized);

    // Replace email addresses.
    $normalized = preg_replace('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', '{EMAIL}', $normalized);

    // Replace URLs (but keep just the path pattern).
    $normalized = preg_replace(
      '%https?://[^\s/$.?#].[^\s]*%i',
      '{URL}',
      $normalized
    );

    // Replace IPv4 addresses when they look variable.
    $normalized = preg_replace('/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/', '{IP}', $normalized);

    // Replace hex strings that look like random tokens (32+ hex chars).
    $normalized = preg_replace('/\b[a-f0-9]{32,}\b/i', '{TOKEN}', $normalized);

    return $normalized;
  }

  /**
   * Extract exception class from context.
   */
  protected function extractExceptionClass(array $context): ?string {
    if (isset($context['exception'])) {
      if ($context['exception'] instanceof \Throwable) {
        return get_class($context['exception']);
      }
      // Sometimes it's stored as a string or serialized.
      if (is_string($context['exception'])) {
        // Try to extract class name from serialized exception or string representation.
        if (strpos($context['exception'], 'Exception') !== FALSE) {
          return $this->extractClassNameFromString($context['exception']);
        }
      }
    }

    // Try common alternative keys.
    $exception_keys = ['error_class', 'exception_class', 'exception_type'];
    foreach ($exception_keys as $key) {
      if (!empty($context[$key])) {
        return (string) $context[$key];
      }
    }

    return NULL;
  }

  /**
   * Extract file from context or exception.
   */
  protected function extractFile(array $context): ?string {
    if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
      $file = $context['exception']->getFile();
      // Make path relative to site root if possible to improve portability.
      return basename($file);
    }

    // Try common context keys.
    if (!empty($context['file'])) {
      return basename((string) $context['file']);
    }

    return NULL;
  }

  /**
   * Extract line number from context or exception.
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
   * Generate a hash of the stack trace for additional grouping accuracy.
   *
   * @param \Throwable $exception
   *   The exception to hash.
   *
   * @return string
   *   Hex hash of the trace.
   */
  protected function hashStackTrace(\Throwable $exception): string {
    $trace = $exception->getTrace();
    // Extract only the class/function/file/line info, not actual values.
    $trace_signature = [];

    foreach ($trace as $frame) {
      $frame_sig = [];
      if (isset($frame['class'])) {
        $frame_sig[] = $frame['class'];
      }
      if (isset($frame['function'])) {
        $frame_sig[] = $frame['function'];
      }
      if (isset($frame['file'])) {
        $frame_sig[] = basename($frame['file']);
      }
      if (isset($frame['line'])) {
        $frame_sig[] = (string) $frame['line'];
      }
      if ($frame_sig) {
        $trace_signature[] = implode(':', $frame_sig);
      }
    }

    $trace_string = implode('|', $trace_signature);
    return hash('md5', $trace_string);
  }

  /**
   * Try to extract a class name from a string representation of an exception.
   */
  protected function extractClassNameFromString(string $str): ?string {
    // Try to match patterns like "Exception: message" or class path.
    if (preg_match('/^[\\]?([A-Z][a-zA-Z0-9\\\\]+Exception)/', $str, $matches)) {
      return $matches[1];
    }

    return NULL;
  }

}

