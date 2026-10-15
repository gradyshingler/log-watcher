<?php

namespace Drupal\log_watcher\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Drupal\log_watcher\Form\DashboardFilterForm;
use Drupal\log_watcher\Repository\ErrorGroupRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for the Log Watcher dashboard.
 */
class DashboardController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The error group repository.
   */
  protected ErrorGroupRepository $repo;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a new dashboard controller.
   */
  public function __construct(ErrorGroupRepository $repo, DateFormatterInterface $date_formatter, FormBuilderInterface $form_builder, RequestStack $request_stack) {
    $this->repo = $repo;
    $this->dateFormatter = $date_formatter;
    $this->formBuilder = $form_builder;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $container->get('log_watcher.error_group_repository');
    /** @var \Drupal\Core\Datetime\DateFormatterInterface $date_formatter */
    $date_formatter = $container->get('date.formatter');
    /** @var \Drupal\Core\Form\FormBuilderInterface $form_builder */
    $form_builder = $container->get('form_builder');
    /** @var \Symfony\Component\HttpFoundation\RequestStack $request_stack */
    $request_stack = $container->get('request_stack');

    return new static(
      $repo,
      $date_formatter,
      $form_builder,
      $request_stack
    );
  }

  /**
   * Render the dashboard.
   */
  public function dashboard(?string $status = NULL) {
    $build = [];
    $request = $this->requestStack->getCurrentRequest();
    $severity_filter = $request->query->get('severity');
    $search_filter = trim((string) $request->query->get('search', ''));
    $count_order = strtoupper((string) $request->query->get('count_order', 'DESC'));
    $count_order = $count_order === 'ASC' ? 'ASC' : 'DESC';
    $severity = ($severity_filter !== NULL && $severity_filter !== '') ? (int) $severity_filter : NULL;
    $list_status = in_array($status, ['resolved', 'ignored'], TRUE) ? $status : NULL;
    $group_status = $list_status ? $list_status : ['new', 'active'];
    $route_name = $list_status === 'resolved' ? 'log_watcher.dashboard_resolved' : ($list_status === 'ignored' ? 'log_watcher.dashboard_ignored' : 'log_watcher.dashboard');
    $route_params = $list_status ? ['status' => $list_status] : [];
    $page_title = $list_status ? ucfirst($list_status) . ' Error Groups' : $this->t('Active Error Groups');
    $filters = [
      'severity' => $severity,
      'search' => $search_filter,
      'count_order' => $count_order,
    ];

    // Summary section.
    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['log-watcher-summary']],
    ];

    $active_count = $this->repo->countGroups(['new', 'active']);
    $resolved_count = $this->repo->countGroups('resolved');
    $ignored_count = $this->repo->countGroups('ignored');
    $active_occurrences = $this->repo->getTotalOccurrences(['new', 'active']);

    $build['summary']['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['log-watcher-stats']],
      'active' => [
        '#markup' => '<div class="stat-item"><strong>' . $this->t('Active Groups') . ':</strong> ' . $active_count . '</div>',
      ],
      'occurrences' => [
        '#markup' => '<div class="stat-item"><strong>' . $this->t('Total Occurrences') . ':</strong> ' . $active_occurrences . '</div>',
      ],
      'resolved' => [
        '#markup' => '<div class="stat-item"><strong>' . $this->t('Resolved Groups') . ':</strong> ' . $resolved_count . '</div>',
      ],
      'ignored' => [
        '#markup' => '<div class="stat-item"><strong>' . $this->t('Ignored Groups') . ':</strong> ' . $ignored_count . '</div>',
      ],
    ];

    // Severity breakdown.
    $severity_counts = $this->repo->getGroupCountsBySeverity($group_status);
    $severity_labels = $this->getSeverityLabels();

    $build['summary']['by_severity'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['log-watcher-severity-breakdown']],
      'heading' => [
        '#markup' => '<h3>' . $this->t('Counts by Severity') . '</h3>',
      ],
    ];

    foreach ($severity_counts as $severity_code => $count) {
      $label = $severity_labels[$severity_code] ?? $severity_code;
      $build['summary']['by_severity']["severity_$severity_code"] = [
        '#markup' => '<div class="severity-item"><strong>' . $label . ':</strong> ' . $count . '</div>',
      ];
    }

    // Error groups table.
    $build['groups'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['log-watcher-groups']],
      'heading' => [
        '#markup' => '<h3>' . $page_title . '</h3>',
      ],
    ];

    if ($list_status) {
      $build['groups']['bulk_delete'] = [
        '#type' => 'link',
        '#title' => $list_status === 'resolved' ? $this->t('Delete all resolved groups') : $this->t('Delete all ignored groups'),
        '#url' => Url::fromRoute('log_watcher.status_delete_all', ['status' => $list_status]),
        '#attributes' => ['class' => ['button', 'button--danger']],
      ];
    }

    $build['groups']['filters'] = $this->formBuilder->getForm(DashboardFilterForm::class, $filters, $route_name, $route_params);

    // Get groups for display.
    $groups = $this->repo->getActiveGroups(NULL, $severity, $group_status, 100, 0, $search_filter, $count_order);

    if (empty($groups)) {
      $empty_message = ($severity !== NULL || $search_filter !== '')
        ? ($list_status === 'resolved'
          ? $this->t('No resolved error groups matched the current filters.')
          : ($list_status === 'ignored'
            ? $this->t('No ignored error groups matched the current filters.')
            : $this->t('No active error groups matched the current filters.')))
        : ($list_status === 'resolved'
          ? $this->t('No resolved error groups.')
          : ($list_status === 'ignored' ? $this->t('No ignored error groups.') : $this->t('No active error groups.')));

      $build['groups']['empty'] = [
        '#markup' => '<p>' . $empty_message . '</p>',
      ];
    } else {
      $rows = [];
      $count_header_direction = $count_order === 'ASC' ? 'DESC' : 'ASC';
      $count_header_query = array_filter([
        'severity' => $severity,
        'search' => $search_filter,
        'count_order' => $count_header_direction,
      ], static fn ($value) => $value !== NULL && $value !== '');

      $count_header_title = $this->t('Count') . ' ' . ($count_order === 'ASC' ? '↑' : '↓');

      foreach ($groups as $group) {
        $row = [
          'severity' => $severity_labels[$group['severity']] ?? $group['severity'],
          'message' => [
            'data' => [
              '#type' => 'link',
              '#title' => substr($group['message'], 0, 80),
              '#url' => Url::fromRoute('log_watcher.group_detail', ['error_group_id' => $group['id']], ['query' => $count_header_query]),
            ],
          ],
          'count' => $group['occurrence_count'],
          'status' => ucfirst($group['status']),
          'last_seen' => $this->formatTimestamp($group['last_seen']),
        ];

        if ($list_status === 'resolved') {
          $row['actions'] = [
            'data' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['log-watcher-row-actions']],
              'reopen' => [
                '#type' => 'link',
                '#title' => $this->t('Reopen'),
                '#url' => Url::fromRoute('log_watcher.group_reopen', ['error_group_id' => $group['id']]),
                '#attributes' => ['class' => ['button', 'button--small']],
              ],
              'delete' => [
                '#type' => 'link',
                '#title' => $this->t('Delete'),
                '#url' => Url::fromRoute('log_watcher.group_delete', ['error_group_id' => $group['id']]),
                '#attributes' => ['class' => ['button', 'button--danger', 'button--small']],
              ],
            ],
          ];
        }
        elseif ($list_status === 'ignored') {
          $row['actions'] = [
            'data' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['log-watcher-row-actions']],
              'unignore' => [
                '#type' => 'link',
                '#title' => $this->t('Unignore'),
                '#url' => Url::fromRoute('log_watcher.group_unignore', ['error_group_id' => $group['id']]),
                '#attributes' => ['class' => ['button', 'button--small']],
              ],
              'delete' => [
                '#type' => 'link',
                '#title' => $this->t('Delete'),
                '#url' => Url::fromRoute('log_watcher.group_delete', ['error_group_id' => $group['id']]),
                '#attributes' => ['class' => ['button', 'button--danger', 'button--small']],
              ],
            ],
          ];
        }

        $rows[] = $row;
      }

      $table_header = [
        'severity' => $this->t('Severity'),
        'message' => $this->t('Message'),
        'count' => [
          'data' => [
            '#type' => 'link',
            '#title' => $count_header_title,
            '#url' => Url::fromRoute($route_name, $route_params, ['query' => $count_header_query]),
          ],
        ],
        'status' => $this->t('Status'),
        'last_seen' => $this->t('Last Seen'),
      ];

      if ($list_status) {
        $table_header['actions'] = $this->t('Actions');
      }

      $build['groups']['table'] = [
        '#type' => 'table',
        '#header' => $table_header,
        '#rows' => $rows,
        '#attributes' => ['class' => ['log-watcher-groups-table']],
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

}




