<?php

namespace Drupal\log_watcher\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\log_watcher\Repository\ErrorGroupRepository;
use Drupal\log_watcher\Repository\OccurrenceRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for detailed error group view.
 */
class GroupDetailController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The error group repository.
   */
  protected ErrorGroupRepository $groupRepo;

  /**
   * The occurrence repository.
   */
  protected OccurrenceRepository $occurrenceRepo;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs a new group detail controller.
   */
  public function __construct(ErrorGroupRepository $group_repo, OccurrenceRepository $occurrence_repo, DateFormatterInterface $date_formatter) {
    $this->groupRepo = $group_repo;
    $this->occurrenceRepo = $occurrence_repo;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('log_watcher.error_group_repository'),
      $container->get('log_watcher.occurrence_repository'),
      $container->get('date.formatter')
    );
  }

  /**
   * Render the group detail page.
   */
  public function detail(int $error_group_id) {
    $group = $this->groupRepo->getGroupById($error_group_id);
    if (!$group) {
      throw new NotFoundHttpException('Error group not found.');
    }

    $build = [];

    // Summary section.
    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['log-watcher-group-summary']],
    ];

    $severity_labels = $this->getSeverityLabels();

    $build['summary']['message'] = [
      '#markup' => '<h2>' . htmlspecialchars($group['message']) . '</h2>',
    ];

    // Details list.
    $details = [];

    $details[] = ['label' => (string) $this->t('Severity'), 'value' => $severity_labels[$group['severity']] ?? $group['severity']];
    $details[] = ['label' => (string) $this->t('Status'), 'value' => ucfirst($group['status'])];
    $details[] = ['label' => (string) $this->t('Channel'), 'value' => htmlspecialchars($group['channel'])];
    $details[] = ['label' => (string) $this->t('Occurrences'), 'value' => $group['occurrence_count']];
    $details[] = ['label' => (string) $this->t('Fingerprint'), 'value' => substr($group['fingerprint'], 0, 16) . '...'];
    $details[] = ['label' => (string) $this->t('First Seen'), 'value' => $this->formatTimestamp($group['first_seen'])];
    $details[] = ['label' => (string) $this->t('Last Seen'), 'value' => $this->formatTimestamp($group['last_seen'])];

    if ($group['exception_class']) {
      $details[] = ['label' => (string) $this->t('Exception Class'), 'value' => htmlspecialchars($group['exception_class'])];
    }

    if ($group['file']) {
      $details[] = ['label' => (string) $this->t('File'), 'value' => htmlspecialchars($group['file'])];
    }

    if ($group['line']) {
      $details[] = ['label' => (string) $this->t('Line'), 'value' => $group['line']];
    }

    if ($group['representative_uri']) {
      $details[] = ['label' => (string) $this->t('Representative URI'), 'value' => htmlspecialchars($group['representative_uri'])];
    }

    if ($group['representative_uid']) {
      $details[] = ['label' => (string) $this->t('Representative User ID'), 'value' => $group['representative_uid']];
    }

    $details_markup = '<dl class="log-watcher-details">';
    foreach ($details as $detail) {
      $details_markup .= '<dt>' . $detail['label'] . '</dt><dd>' . $detail['value'] . '</dd>';
    }
    $details_markup .= '</dl>';

    $build['summary']['details'] = [
      '#markup' => $details_markup,
    ];

    // Action buttons.
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['log-watcher-actions']],
    ];

    if ($group['status'] === 'new' || $group['status'] === 'active') {
      $build['actions']['resolve'] = [
        '#type' => 'link',
        '#title' => $this->t('Mark Resolved'),
        '#url' => Url::fromRoute('log_watcher.group_resolve', ['error_group_id' => $error_group_id]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    } elseif ($group['status'] === 'resolved') {
      $build['actions']['reopen'] = [
        '#type' => 'link',
        '#title' => $this->t('Reopen'),
        '#url' => Url::fromRoute('log_watcher.group_reopen', ['error_group_id' => $error_group_id]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    if ($group['status'] !== 'ignored') {
      $build['actions']['ignore'] = [
        '#type' => 'link',
        '#title' => $this->t('Ignore'),
        '#url' => Url::fromRoute('log_watcher.group_ignore', ['error_group_id' => $error_group_id]),
        '#attributes' => ['class' => ['button']],
      ];
    } else {
      $build['actions']['unignore'] = [
        '#type' => 'link',
        '#title' => $this->t('Stop Ignoring'),
        '#url' => Url::fromRoute('log_watcher.group_unignore', ['error_group_id' => $error_group_id]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $build['actions']['delete'] = [
      '#type' => 'link',
      '#title' => $this->t('Delete'),
      '#url' => Url::fromRoute('log_watcher.group_delete', ['error_group_id' => $error_group_id]),
      '#attributes' => ['class' => ['button', 'button--danger']],
    ];

    // Context data if available.
    if ($group['representative_context']) {
      $build['context'] = [
        '#type' => 'details',
        '#title' => $this->t('Representative Context'),
        '#open' => FALSE,
      ];

      try {
        $context = $this->decodeStoredContext($group['representative_context']);
        $context_markup = '<pre>' . htmlspecialchars(print_r($context, TRUE)) . '</pre>';
        $build['context']['data'] = [
          '#markup' => $context_markup,
        ];
      } catch (\Throwable $e) {
        $build['context']['data'] = [
          '#markup' => '<p>' . $this->t('Could not decode context data.') . '</p>',
        ];
      }
    }

    // Recent occurrences.
    $build['occurrences'] = [
      '#type' => 'details',
      '#title' => $this->t('Recent Occurrences'),
      '#open' => TRUE,
    ];

    $occurrences = $this->occurrenceRepo->getRecentByGroup($error_group_id, 10);

    if (empty($occurrences)) {
      $build['occurrences']['empty'] = [
        '#markup' => '<p>' . $this->t('No occurrences recorded.') . '</p>',
      ];
    } else {
      $rows = [];
      foreach ($occurrences as $occurrence) {
        $rows[] = [
          'occurred' => $this->formatTimestamp($occurrence['occurred_at']),
          'message' => substr($occurrence['message'] ?? '', 0, 100),
          'user' => $occurrence['uid'] ?? '-',
          'uri' => htmlspecialchars(substr($occurrence['uri'] ?? '', 0, 80)),
        ];
      }

      $build['occurrences']['table'] = [
        '#type' => 'table',
        '#header' => [
          'occurred' => $this->t('Occurred'),
          'message' => $this->t('Message'),
          'user' => $this->t('User'),
          'uri' => $this->t('URI'),
        ],
        '#rows' => $rows,
      ];
    }

    return $build;
  }

  /**
   * Get RFC 5424 severity labels.
   */
  protected function getSeverityLabels(): array {
    return [
      0 => $this->t('Emergency'),
      1 => $this->t('Alert'),
      2 => $this->t('Critical'),
      3 => $this->t('Error'),
      4 => $this->t('Warning'),
      5 => $this->t('Notice'),
      6 => $this->t('Informational'),
      7 => $this->t('Debug'),
    ];
  }

  /**
   * Format a Unix timestamp for display.
   */
  protected function formatTimestamp(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'short');
  }

  /**
   * Decode stored context safely without allowing object instantiation.
   */
  protected function decodeStoredContext(string $raw): mixed {
    $raw = trim($raw);
    if ($raw === '') {
      return [];
    }

    $json = json_decode($raw, TRUE);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
      return $json;
    }

    $data = @unserialize($raw, ['allowed_classes' => FALSE]);
    if ($data === FALSE && $raw !== 'b:0;') {
      return ['__unreadable__' => TRUE];
    }

    return $data;
  }

}





