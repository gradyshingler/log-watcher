<?php

namespace Drupal\log_watcher\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Drupal\log_watcher\Service\ErrorGroupService;
use Drupal\log_watcher\Service\ErrorFingerprintService;
use Drupal\log_watcher\Service\ConfigService;
use Drupal\log_watcher\Service\OccurrenceService;

/**
 * Logger implementation that captures log events for Log Watcher.
 *
 * This service is tagged as a logger and registered with Drupal's central
 * logging system. It receives all log events and decides which ones to capture.
 *
 * This implementation is carefully designed to:
 * - Prevent recursive logging (Log Watcher logging its own failures)
 * - Handle exceptions gracefully
 * - Not interfere with normal Drupal logging
 * - Be performant even with high log volume
 */
class LogWatcherLogger extends AbstractLogger {

  /**
   * The error group service.
   *
   * @var \Drupal\log_watcher\Service\ErrorGroupService
   */
  protected ErrorGroupService $errorGroupService;

  /**
   * The fingerprint service.
   *
   * @var \Drupal\log_watcher\Service\ErrorFingerprintService
   */
  protected ErrorFingerprintService $fingerprintService;

  /**
   * The config service.
   *
   * @var \Drupal\log_watcher\Service\ConfigService
   */
  protected ConfigService $configService;

  /**
   * The occurrence service.
   */
  protected OccurrenceService $occurrenceService;

  /**
   * Flag to prevent recursive logging.
   *
   * @var bool
   */
  protected static bool $processing = FALSE;

  /**
   * RFC 5424 severity level constants for easy reference.
   */
  protected const SEVERITY_EMERGENCY = 0;
  protected const SEVERITY_ALERT = 1;
  protected const SEVERITY_CRITICAL = 2;
  protected const SEVERITY_ERROR = 3;
  protected const SEVERITY_WARNING = 4;
  protected const SEVERITY_NOTICE = 5;
  protected const SEVERITY_INFO = 6;
  protected const SEVERITY_DEBUG = 7;

  /**
   * Constructor.
   */
  public function __construct(
    ErrorGroupService $error_group_service,
    ErrorFingerprintService $fingerprint_service,
    ConfigService $config_service,
    OccurrenceService $occurrence_service
  ) {
    $this->errorGroupService = $error_group_service;
    $this->fingerprintService = $fingerprint_service;
    $this->configService = $config_service;
    $this->occurrenceService = $occurrence_service;
  }

  /**
   * Implements LoggerInterface::log().
   *
   * This is the main entry point for log events from Drupal's central
   * logging system.
   */
  public function log($level, $message, array $context = []): void {
    // Prevent recursive logging if Log Watcher itself generates errors.
    if (self::$processing) {
      return;
    }

    // Check if Log Watcher is enabled and if this severity level should be monitored.
    if (!$this->configService->isEnabled()) {
      return;
    }

    $severity = $this->psrLevelToRfc5424($level);
    if (!$this->shouldMonitorSeverity($severity)) {
      return;
    }

    // Set processing flag to prevent recursion.
    self::$processing = TRUE;

    try {
      $this->processLogEvent($level, $message, $context, $severity);
    } catch (\Exception $e) {
      // Never let logging failures bubble up or recurse into the logger stack.
    } finally {
      self::$processing = FALSE;
    }
  }

  /**
   * Process a log event that passed filtering.
   */
  protected function processLogEvent(
    $level,
    string $message,
    array $context,
    int $severity
  ): void {
    // Ensure we have a channel in context.
    $channel = $context['channel'] ?? 'unknown';

    try {
      // Find or create the error group.
      $group = $this->errorGroupService->findOrCreateAndIncrement(
        $channel,
        $severity,
        $message,
        $context
      );

      // Record this individual occurrence.
      if ($group) {
        $max_occurrences = $this->configService->getMaxOccurrencesPerGroup();

        $watchdog_id = $context['watchdog_id'] ?? NULL;
        $this->occurrenceService->recordOccurrence(
          $group['id'],
          $message,
          $context,
          $watchdog_id,
          $max_occurrences
        );
      }
    } catch (\Exception $e) {
      // Never let storage failures bubble up or recurse into the logger stack.
    }
  }

  /**
   * Check if this severity level should be monitored.
   */
  protected function shouldMonitorSeverity(int $severity): bool {
    $monitored = $this->configService->getMonitoredSeverities();
    return in_array($severity, $monitored, TRUE);
  }

  /**
   * Convert PSR-3 log level to RFC 5424 severity.
   *
   * @param string $psr_level
   *   PSR-3 log level (e.g., LogLevel::ERROR).
   *
   * @return int
   *   RFC 5424 severity level (0-7).
   */
  protected function psrLevelToRfc5424($level): int {
    // Some Drupal logger implementations provide the RFC 5424 numeric
    // severity directly instead of the PSR-3 level name. Preserve that value
    // rather than treating it as an unknown level (which defaults to info).
    if (is_numeric($level)) {
      $severity = (int) $level;
      if ($severity >= self::SEVERITY_EMERGENCY && $severity <= self::SEVERITY_DEBUG) {
        return $severity;
      }
    }

    return match(strtolower((string) $level)) {
      LogLevel::EMERGENCY => self::SEVERITY_EMERGENCY,
      LogLevel::ALERT => self::SEVERITY_ALERT,
      LogLevel::CRITICAL => self::SEVERITY_CRITICAL,
      LogLevel::ERROR => self::SEVERITY_ERROR,
      LogLevel::WARNING => self::SEVERITY_WARNING,
      LogLevel::NOTICE => self::SEVERITY_NOTICE,
      LogLevel::INFO => self::SEVERITY_INFO,
      LogLevel::DEBUG => self::SEVERITY_DEBUG,
      default => self::SEVERITY_INFO,
    };
  }

}



