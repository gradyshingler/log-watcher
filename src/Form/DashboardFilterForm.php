<?php

namespace Drupal\log_watcher\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Filter form for the Log Watcher dashboard.
 */
class DashboardFilterForm extends FormBase {

  /**
   * The route name the form should return to.
   */
  protected string $routeName = 'log_watcher.dashboard';

  /**
   * Route parameters for the return URL.
   */
  protected array $routeParams = [];

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'log_watcher_dashboard_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $filters = [], string $route_name = 'log_watcher.dashboard', array $route_params = []) {
    $this->routeName = $route_name;
    $this->routeParams = $route_params;

    $severity_labels = [
      '' => $this->t('- All severities -'),
      '0' => $this->t('Emergency'),
      '1' => $this->t('Alert'),
      '2' => $this->t('Critical'),
      '3' => $this->t('Error'),
      '4' => $this->t('Warning'),
      '5' => $this->t('Notice'),
      '6' => $this->t('Informational'),
      '7' => $this->t('Debug'),
    ];

    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute($this->routeName, $this->routeParams)->toString();
    $form['#attributes']['class'][] = 'log-watcher-dashboard-filters';

    $form['severity'] = [
      '#type' => 'select',
      '#title' => $this->t('Severity'),
      '#options' => $severity_labels,
      '#default_value' => isset($filters['severity']) && $filters['severity'] !== NULL ? (string) $filters['severity'] : '',
    ];

    $form['search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message contains'),
      '#default_value' => $filters['search'] ?? '',
      '#size' => 30,
      '#maxlength' => 255,
    ];

    $form['count_order'] = [
      '#type' => 'select',
      '#title' => $this->t('Count order'),
      '#options' => [
        'DESC' => $this->t('Highest first'),
        'ASC' => $this->t('Lowest first'),
      ],
      '#default_value' => $filters['count_order'] ?? 'DESC',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply filters'),
    ];

    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromRoute($this->routeName, $this->routeParams),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $query = [];

    $severity = $form_state->getValue('severity');
    if ($severity !== NULL && $severity !== '') {
      $query['severity'] = $severity;
    }

    $search = trim((string) $form_state->getValue('search'));
    if ($search !== '') {
      $query['search'] = $search;
    }

    $count_order = strtoupper((string) $form_state->getValue('count_order', 'DESC'));
    if ($count_order === 'ASC') {
      $query['count_order'] = 'ASC';
    }

    $form_state->setRedirectUrl(Url::fromRoute($this->routeName, $this->routeParams, ['query' => $query]));
  }

}



