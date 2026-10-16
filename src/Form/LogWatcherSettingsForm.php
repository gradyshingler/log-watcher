<?php

namespace Drupal\log_watcher\Form;

use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\log_watcher\Repository\DigestStateRepository;
use Drupal\log_watcher\Service\MaintenanceService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LogLevel;

/**
 * Settings form for Log Watcher configuration.
 */
class LogWatcherSettingsForm extends ConfigFormBase implements ContainerInjectionInterface {

  /**
   * Email validator service.
   */
  protected EmailValidator $emailValidator;

  /**
   * Digest state repository.
   */
  protected DigestStateRepository $digestStateRepository;

  /**
   * Maintenance service.
   */
  protected MaintenanceService $maintenanceService;

  /**
   * Date formatter service.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs a new settings form.
   */
  public function __construct(EmailValidator $email_validator, DigestStateRepository $digest_state_repository, MaintenanceService $maintenance_service, DateFormatterInterface $date_formatter) {
    $this->emailValidator = $email_validator;
    $this->digestStateRepository = $digest_state_repository;
    $this->maintenanceService = $maintenance_service;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('email.validator'),
      $container->get('log_watcher.digest_state_repository'),
      $container->get('log_watcher.maintenance_service'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['log_watcher.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'log_watcher_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('log_watcher.settings');

    $form['#attached']['library'][] = 'log_watcher/settings';
    $digest_status = $this->maintenanceService->getDigestStatus();
    $digest_state = $this->digestStateRepository->getState() ?? [];

    $form['#tree'] = TRUE;

    $form['digest_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Digest Status'),
      '#open' => FALSE,
      '#weight' => 100,
    ];

    $form['digest_status']['summary'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Last email sent: @value', ['@value' => $this->formatDigestTimestamp($digest_state['last_sent'] ?? NULL)]),
        $this->t('Next email due: @value', ['@value' => $this->formatNextDueDescription($digest_status)]),
        $this->t('Configured recipients: @count', ['@count' => $digest_status['recipient_count']]),
        $this->t('Digest frequency: @value', ['@value' => $this->formatFrequencyLabel($digest_status['frequency'])]),
      ],
    ];

    if (!empty($digest_state['last_failure_message'])) {
      $form['digest_status']['last_failure'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'markup' => [
          '#markup' => '<strong>' . $this->t('Last send failure:') . '</strong> ' . Html::escape($digest_state['last_failure_message']),
        ],
      ];
    }

    if (!empty($digest_state['last_subject']) || !empty($digest_state['last_body'])) {
      $form['digest_status']['last_email'] = [
        '#type' => 'details',
        '#title' => $this->t('Last Email Contents'),
        '#open' => FALSE,
      ];

      if (!empty($digest_state['last_subject'])) {
        $form['digest_status']['last_email']['subject'] = [
          '#markup' => '<p><strong>' . $this->t('Subject:') . '</strong> ' . Html::escape($digest_state['last_subject']) . '</p>',
        ];
      }

      if (!empty($digest_state['last_body'])) {
        $form['digest_status']['last_email']['body'] = [
          '#markup' => '<pre>' . Html::escape($digest_state['last_body']) . '</pre>',
        ];
      }
    }

    $form['digest_status']['send_now_help'] = [
      '#markup' => '<p>' . $this->t('Use the button below to send a digest immediately using the currently saved configuration.') . '</p>',
    ];

    $form['digest_status']['send_now'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send Email Now'),
      '#name' => 'send_now',
      '#submit' => ['::sendNowSubmit'],
      '#limit_validation_errors' => [],
      '#button_type' => 'secondary',
    ];

    // Enable/disable.
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Log Watcher'),
      '#default_value' => $config->get('enabled'),
      '#description' => $this->t('When enabled, Log Watcher will capture and aggregate log events.'),
    ];

    $form['monitoring'] = [
      '#type' => 'details',
      '#title' => $this->t('Monitoring'),
      '#open' => TRUE,
    ];

    // Severity levels.
    $form['monitoring']['severity_controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['log-watcher-severity-controls']],
      '#weight' => -1,
    ];

    $form['monitoring']['severity_controls']['select'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Select all'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['button', 'button--small'],
        'data-log-watcher-severity-action' => 'select',
      ],
    ];

    $form['monitoring']['severity_controls']['deselect'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Deselect all'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['button', 'button--small'],
        'data-log-watcher-severity-action' => 'deselect',
      ],
    ];

    $form['monitoring']['severity_levels'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Severity Levels to Monitor'),
      '#description' => $this->t('Select which RFC 5424 severity levels should be captured.'),
      '#options' => [
        '0' => $this->t('Emergency'),
        '1' => $this->t('Alert'),
        '2' => $this->t('Critical'),
        '3' => $this->t('Error'),
        '4' => $this->t('Warning'),
        '5' => $this->t('Notice'),
        '6' => $this->t('Informational'),
        '7' => $this->t('Debug'),
      ],
      '#default_value' => array_map('strval', $config->get('severity_levels') ?? []),
    ];

    // Email configuration.
    $form['email'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Digest'),
      '#open' => TRUE,
    ];

    $form['email']['email_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Digest Frequency'),
      '#description' => $this->t('How often to send email digests. Actual timing depends on when Drupal cron runs.'),
      '#options' => [
        'every_run' => $this->t('Every cron run'),
        'daily' => $this->t('Daily'),
        'every_2_days' => $this->t('Every 2 days'),
        'weekly' => $this->t('Weekly'),
        'biweekly' => $this->t('Every 2 weeks'),
        'monthly' => $this->t('Monthly'),
        'every_friday' => $this->t('Every Friday'),
      ],
      '#default_value' => $config->get('email_frequency') ?? 'weekly',
    ];

    $form['email']['email_recipients'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email Recipients'),
      '#description' => $this->t('One email address per line. Digests will be sent to these addresses.'),
      '#default_value' => $config->get('email_recipients') ?? '',
      '#rows' => 5,
    ];

    $form['email']['send_no_error_digest'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send digest when no errors detected'),
      '#description' => $this->t('If unchecked, no email will be sent when there are no qualifying errors.'),
      '#default_value' => $config->get('send_no_error_digest') ?? TRUE,
    ];

    $form['email']['email_subject_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email Subject Prefix'),
      '#default_value' => $config->get('email_subject_prefix') ?? 'Log Watcher Report',
      '#size' => 50,
    ];

    $form['email']['max_groups_per_email'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Error Groups per Email'),
      '#default_value' => $config->get('max_groups_per_email') ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Limit the number of error groups included in each email to keep messages concise.'),
    ];

    // Retention and limits.
    $form['retention'] = [
      '#type' => 'details',
      '#title' => $this->t('Retention & Limits'),
      '#open' => FALSE,
    ];

    $form['retention']['max_active_groups'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Active Error Groups'),
      '#default_value' => $config->get('max_active_groups') ?? 1000,
      '#min' => 10,
      '#description' => $this->t('Prevents unbounded growth. Oldest groups will be deleted when limit is reached.'),
    ];

    $form['retention']['max_occurrences_per_group'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Recent Occurrences per Group'),
      '#default_value' => $config->get('max_occurrences_per_group') ?? 50,
      '#min' => 5,
      '#description' => $this->t('Only keeps this many recent occurrences per group to limit database growth.'),
    ];

    $form['retention']['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Retention Period for Active Groups (days)'),
      '#default_value' => $config->get('retention_days') ?? 90,
      '#min' => 1,
      '#description' => $this->t('Active groups older than this will be deleted during cleanup.'),
    ];

    $form['retention']['resolved_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Retention Period for Resolved Groups (days)'),
      '#default_value' => $config->get('resolved_retention_days') ?? 30,
      '#min' => 1,
      '#description' => $this->t('Resolved groups older than this will be deleted during cleanup.'),
    ];

    $form['retention']['ignored_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Retention Period for Ignored Groups (days)'),
      '#default_value' => $config->get('ignored_retention_days') ?? 14,
      '#min' => 1,
      '#description' => $this->t('Ignored groups older than this will be deleted during cleanup.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    if (!empty($trigger['#name']) && $trigger['#name'] === 'send_now') {
      return;
    }

    parent::validateForm($form, $form_state);

    // Validate email addresses if any are provided.
    $recipients = $form_state->getValue(['email', 'email_recipients']);
    if ($recipients) {
      $lines = array_filter(array_map('trim', explode("\n", $recipients)));
      foreach ($lines as $line) {
        if (!$this->emailValidator->isValid($line)) {
          $form_state->setErrorByName('email][email_recipients', $this->t('Invalid email address: @email', ['@email' => $line]));
        }
      }
    }

    // Validate that at least one severity is selected if enabled.
    if ($form_state->getValue('enabled')) {
      $severities = $this->normalizeSeveritySelection($form_state->getValue(['monitoring', 'severity_levels']) ?? []);
      if (empty($severities)) {
        $form_state->setErrorByName('monitoring][severity_levels', $this->t('At least one severity level must be selected when Log Watcher is enabled.'));
      }
    }

    // Validate that numeric limits are reasonable.
    $max_active = (int) $form_state->getValue(['retention', 'max_active_groups']);
    if ($max_active < 10) {
      $form_state->setErrorByName('retention][max_active_groups', $this->t('Maximum active groups must be at least 10.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    if (!empty($trigger['#name']) && $trigger['#name'] === 'send_now') {
      return;
    }

    // Extract and clean up severity levels (remove unchecked items).
    $severities = $this->normalizeSeveritySelection($form_state->getValue(['monitoring', 'severity_levels']) ?? []);

    // Clean email recipients.
    $recipients = $form_state->getValue(['email', 'email_recipients']);
    $recipients_clean = [];
    if ($recipients) {
      $lines = array_filter(array_map('trim', explode("\n", $recipients)));
      $recipients_clean = implode("\n", $lines);
    }

    $this->config('log_watcher.settings')
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('severity_levels', $severities)
      ->set('email_frequency', $form_state->getValue(['email', 'email_frequency']))
      ->set('email_recipients', $recipients_clean)
      ->set('send_no_error_digest', $form_state->getValue(['email', 'send_no_error_digest']))
      ->set('email_subject_prefix', $form_state->getValue(['email', 'email_subject_prefix']))
      ->set('max_groups_per_email', (int) $form_state->getValue(['email', 'max_groups_per_email']))
      ->set('max_active_groups', (int) $form_state->getValue(['retention', 'max_active_groups']))
      ->set('max_occurrences_per_group', (int) $form_state->getValue(['retention', 'max_occurrences_per_group']))
      ->set('retention_days', (int) $form_state->getValue(['retention', 'retention_days']))
      ->set('resolved_retention_days', (int) $form_state->getValue(['retention', 'resolved_retention_days']))
      ->set('ignored_retention_days', (int) $form_state->getValue(['retention', 'ignored_retention_days']))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Send the digest immediately using the saved configuration.
   */
  public function sendNowSubmit(array &$form, FormStateInterface $form_state) {
    try {
      $result = $this->maintenanceService->sendDigestNow();
      if (!empty($result['skipped'])) {
        $this->messenger()->addStatus($this->t('No digest email was sent because there were no qualifying errors and no-error digests are currently disabled.'));
      }
      else {
        $this->messenger()->addStatus($this->t('Digest email sent successfully to @count recipient(s).', ['@count' => $result['sent_count'] ?? 0]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Unable to send the digest email now: @message', ['@message' => $e->getMessage()]));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * Normalize submitted severity checkbox values.
   *
   * Drupal checkboxes use 0 for unchecked values, which conflicts with the
   * Emergency severity key of 0. This method preserves an explicitly selected
   * Emergency option while filtering out unchecked items.
   *
   * @param array $values
   *   Raw submitted checkbox values.
   *
   * @return array
   *   Normalized severity integers.
   */
  protected function normalizeSeveritySelection(array $values): array {
    $selected = [];

    foreach ($values as $key => $value) {
      if ($value !== 0 && $value !== NULL && $value !== FALSE && $value !== '') {
        $selected[] = (int) $key;
      }
    }

    return $selected;
  }

  /**
   * Format a digest timestamp for display.
   */
  protected function formatDigestTimestamp(?int $timestamp): string {
    if (empty($timestamp)) {
      return (string) $this->t('Never');
    }

    return $this->dateFormatter->format($timestamp, 'short');
  }

  /**
   * Format the next due description for administrative display.
   */
  protected function formatNextDueDescription(array $status): string {
    $next_due = $status['next_due'] ?? NULL;
    if (($status['frequency'] ?? '') === 'every_run') {
      return (string) $this->t('At the next cron run');
    }

    if ($next_due === NULL) {
      return (string) $this->t('Unknown');
    }

    $now = \Drupal::time()->getRequestTime();
    if (!empty($status['due_now']) || $next_due <= $now) {
      return (string) $this->t('Due now (will send on the next cron run)');
    }

    return $this->t('@date (@interval remaining)', [
      '@date' => $this->dateFormatter->format($next_due, 'short'),
      '@interval' => $this->dateFormatter->formatInterval($next_due - $now),
    ])->render();
  }

  /**
   * Format the configured frequency label.
   */
  protected function formatFrequencyLabel(string $frequency): string {
    $labels = [
      'every_run' => (string) $this->t('Every cron run'),
      'daily' => (string) $this->t('Daily'),
      'every_2_days' => (string) $this->t('Every 2 days'),
      'weekly' => (string) $this->t('Weekly'),
      'biweekly' => (string) $this->t('Every 2 weeks'),
      'monthly' => (string) $this->t('Monthly'),
      'every_friday' => (string) $this->t('Every Friday'),
    ];

    return $labels[$frequency] ?? $frequency;
  }

}
