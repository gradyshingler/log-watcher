<?php

namespace Drupal\log_watcher\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\log_watcher\Service\MaintenanceService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for Log Watcher maintenance operations.
 */
class MaintenanceForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The database connection.
   */
  protected Connection $connection;

  /**
   * The maintenance service.
   */
  protected MaintenanceService $maintenanceService;

  /**
   * Constructs a new maintenance form.
   */
  public function __construct(Connection $connection, MaintenanceService $maintenance_service) {
    $this->connection = $connection;
    $this->maintenanceService = $maintenance_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('log_watcher.maintenance_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'log_watcher_maintenance_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Use these tools to clean up Log Watcher data.') . '</p>',
    ];

    // Clear resolved groups.
    $form['clear_resolved'] = [
      '#type' => 'details',
      '#title' => $this->t('Clear Resolved Groups'),
      '#open' => FALSE,
    ];

    $form['clear_resolved']['description'] = [
      '#markup' => '<p>' . $this->t('Permanently delete all resolved error groups and their occurrences.') . '</p>',
    ];

    $form['clear_resolved']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Resolved Groups'),
      '#name' => 'clear_resolved',
      '#attributes' => ['class' => ['button--danger']],
    ];

    // Clear ignored groups.
    $form['clear_ignored'] = [
      '#type' => 'details',
      '#title' => $this->t('Clear Ignored Groups'),
      '#open' => FALSE,
    ];

    $form['clear_ignored']['description'] = [
      '#markup' => '<p>' . $this->t('Permanently delete all ignored error groups and their occurrences.') . '</p>',
    ];

    $form['clear_ignored']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Ignored Groups'),
      '#name' => 'clear_ignored',
      '#attributes' => ['class' => ['button--danger']],
    ];

    // Clear all data.
    $form['clear_all'] = [
      '#type' => 'details',
      '#title' => $this->t('Clear All Log Watcher Data'),
      '#open' => FALSE,
    ];

    $form['clear_all']['warning'] = [
      '#markup' => '<p><strong>' . $this->t('Warning: This will delete all error groups and occurrences.') . '</strong></p>',
    ];

    $form['clear_all']['description'] = [
      '#markup' => '<p>' . $this->t('This action cannot be undone. All Log Watcher data will be permanently deleted.') . '</p>',
    ];

    $form['clear_all']['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand this will permanently delete all Log Watcher data.'),
      '#required' => FALSE,
    ];

    $form['clear_all']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear All Data'),
      '#name' => 'clear_all',
      '#attributes' => ['class' => ['button--danger']],
      '#states' => [
        'enabled' => [
          ':input[name="clear_all[confirm]"]' => ['checked' => TRUE],
        ],
        'disabled' => [
          ':input[name="clear_all[confirm]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    // Run cleanup.
    $form['run_cleanup'] = [
      '#type' => 'details',
      '#title' => $this->t('Run Cleanup'),
      '#open' => FALSE,
    ];

    $form['run_cleanup']['description'] = [
      '#markup' => '<p>' . $this->t('Run data retention and cleanup according to configured limits.') . '</p>',
    ];

    $form['run_cleanup']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run Cleanup Now'),
      '#name' => 'run_cleanup',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate clear all confirmation.
    $trigger = $form_state->getTriggeringElement();
    if ($trigger['#name'] === 'clear_all' && !$form_state->getValue(['clear_all', 'confirm'])) {
      $form_state->setErrorByName('clear_all][confirm', $this->t('You must confirm this action.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();

    switch ($trigger['#name']) {
      case 'clear_resolved':
        $this->clearGroupsByStatus($this->connection, 'resolved');
        $this->messenger()->addMessage($this->t('Resolved groups cleared.'));
        break;

      case 'clear_ignored':
        $this->clearGroupsByStatus($this->connection, 'ignored');
        $this->messenger()->addMessage($this->t('Ignored groups cleared.'));
        break;

      case 'clear_all':
        $this->clearAllData($this->connection);
        $this->messenger()->addMessage($this->t('All Log Watcher data cleared.'));
        break;

      case 'run_cleanup':
        $this->maintenanceService->cleanupOldData();
        $this->messenger()->addMessage($this->t('Cleanup completed.'));
        break;
    }
  }

  /**
   * Clear all groups with a given status.
   */
  protected function clearGroupsByStatus($connection, string $status): void {
    $group_ids = $connection->select('log_watcher_error_groups', 'g')
      ->fields('g', ['id'])
      ->condition('status', $status)
      ->execute()
      ->fetchAllKeyed(0, 0);

    if (!empty($group_ids)) {
      $connection->delete('log_watcher_occurrences')
        ->condition('group_id', array_keys($group_ids), 'IN')
        ->execute();

      $connection->delete('log_watcher_error_groups')
        ->condition('id', array_keys($group_ids), 'IN')
        ->execute();
    }
  }

  /**
   * Clear all Log Watcher data.
   */
  protected function clearAllData($connection): void {
    $connection->truncate('log_watcher_occurrences')->execute();
    $connection->truncate('log_watcher_error_groups')->execute();
  }

}


