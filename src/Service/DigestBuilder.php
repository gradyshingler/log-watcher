<?php

namespace Drupal\log_watcher\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\log_watcher\Repository\ErrorGroupRepository;
use Drupal\Core\Url;

/**
 * Service for building email digest content.
 */
class DigestBuilder {

  /**
   * The config service.
   *
   * @var \Drupal\log_watcher\Service\ConfigService
   */
  protected ConfigService $configService;

  /**
   * The error group repository.
   *
   * @var \Drupal\log_watcher\Repository\ErrorGroupRepository
   */
  protected ErrorGroupRepository $groupRepository;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructor.
   */
  public function __construct(
    ConfigService $config_service,
    ErrorGroupRepository $group_repository,
    DateFormatterInterface $date_formatter
  ) {
    $this->configService = $config_service;
    $this->groupRepository = $group_repository;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * Build email subject line.
   */
  public function buildSubject(int $group_count, int $total_count): string {
    $prefix = $this->configService->getEmailSubjectPrefix();

    if ($group_count === 0) {
      return "$prefix: No qualifying errors detected";
    }

    return "$prefix: $group_count error group" . ($group_count !== 1 ? 's' : '') . " detected";
  }

  /**
   * Build email body as plain text.
   */
  public function buildBodyText(array $groups, int $total_groups, int $total_occurrences): string {
    $lines = [];
    $lines[] = "Log Watcher Report";
    $lines[] = str_repeat("=", 50);
    $lines[] = "";

    if (empty($groups)) {
      $lines[] = "No qualifying errors were detected during this reporting period.";
      $lines[] = "";
    } else {
      $lines[] = "Summary:";
      $lines[] = "  Total Active Groups: $total_groups";
      $lines[] = "  Total Occurrences: $total_occurrences";
      $lines[] = "";
      $lines[] = "Top Error Groups:";
      $lines[] = "";

      $severity_labels = $this->getSeverityLabels();

      foreach ($groups as $group) {
        $lines[] = "---";
        $lines[] = "Severity: " . ($severity_labels[$group['severity']] ?? $group['severity']);
        $lines[] = "Message: " . substr($group['message'], 0, 100);
        $lines[] = "Occurrences: " . $group['occurrence_count'];
        $lines[] = "First Seen: " . $this->formatTimestamp($group['first_seen']);
        $lines[] = "Last Seen: " . $this->formatTimestamp($group['last_seen']);
        $lines[] = "Channel: " . $group['channel'];

        $url = Url::fromRoute('log_watcher.group_detail', ['error_group_id' => $group['id']], ['absolute' => TRUE]);
        $lines[] = "View Details: " . $url->toString();
        $lines[] = "";
      }
    }

    $lines[] = "---";
    $lines[] = "View Dashboard: " . Url::fromRoute('log_watcher.dashboard', [], ['absolute' => TRUE])->toString();
    $lines[] = "";

    return implode("\n", $lines);
  }

  /**
   * Build email body as HTML.
   */
  public function buildBodyHtml(array $groups, int $total_groups, int $total_occurrences): string {
    $severity_labels = $this->getSeverityLabels();

    $html = '<html><body>';
    $html .= '<h1>Log Watcher Report</h1>';

    if (empty($groups)) {
      $html .= '<p>No qualifying errors were detected during this reporting period.</p>';
    } else {
      $html .= '<h2>Summary</h2>';
      $html .= '<ul>';
      $html .= '<li>Total Active Groups: <strong>' . $total_groups . '</strong></li>';
      $html .= '<li>Total Occurrences: <strong>' . $total_occurrences . '</strong></li>';
      $html .= '</ul>';

      $html .= '<h2>Top Error Groups</h2>';
      $html .= '<table border="1" cellpadding="5" style="border-collapse: collapse; width: 100%;">';
      $html .= '<tr>';
      $html .= '<th>Severity</th>';
      $html .= '<th>Message</th>';
      $html .= '<th>Count</th>';
      $html .= '<th>Last Seen</th>';
      $html .= '<th>Link</th>';
      $html .= '</tr>';

      foreach ($groups as $group) {
        $url = Url::fromRoute('log_watcher.group_detail', ['error_group_id' => $group['id']], ['absolute' => TRUE]);
        $severity = $severity_labels[$group['severity']] ?? $group['severity'];
        $message = htmlspecialchars(substr($group['message'], 0, 100));
        $count = $group['occurrence_count'];
        $last_seen = $this->formatTimestamp($group['last_seen']);
        $link = '<a href="' . htmlspecialchars($url->toString()) . '">View</a>';

        $html .= '<tr>';
        $html .= '<td>' . $severity . '</td>';
        $html .= '<td>' . $message . '</td>';
        $html .= '<td>' . $count . '</td>';
        $html .= '<td>' . $last_seen . '</td>';
        $html .= '<td>' . $link . '</td>';
        $html .= '</tr>';
      }

      $html .= '</table>';
    }

    $dashboard_url = Url::fromRoute('log_watcher.dashboard', [], ['absolute' => TRUE])->toString();
    $html .= '<p><a href="' . htmlspecialchars($dashboard_url) . '">View Full Dashboard</a></p>';

    $html .= '</body></html>';

    return $html;
  }

  /**
   * Get RFC 5424 severity labels.
   */
  protected function getSeverityLabels(): array {
    return [
      0 => 'Emergency',
      1 => 'Alert',
      2 => 'Critical',
      3 => 'Error',
      4 => 'Warning',
      5 => 'Notice',
      6 => 'Informational',
      7 => 'Debug',
    ];
  }

  /**
   * Format a Unix timestamp for display.
   */
  protected function formatTimestamp(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'short');
  }

}


