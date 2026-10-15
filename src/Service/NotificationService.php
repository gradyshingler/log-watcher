<?php

namespace Drupal\log_watcher\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\log_watcher\Repository\DigestStateRepository;
use Drupal\log_watcher\Repository\ErrorGroupRepository;
use Drupal\log_watcher\Repository\OccurrenceRepository;
use Drupal\Core\Mail\MailManagerInterface;

/**
 * Service for building and sending email digests.
 */
class NotificationService {

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
   * The occurrence repository.
   *
   * @var \Drupal\log_watcher\Repository\OccurrenceRepository
   */
  protected OccurrenceRepository $occurrenceRepository;

  /**
   * The digest builder.
   */
  protected DigestBuilder $digestBuilder;

  /**
   * The digest state repository.
   */
  protected DigestStateRepository $digestStateRepository;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * Constructor.
   */
  public function __construct(
    ConfigService $config_service,
    ErrorGroupRepository $group_repository,
    OccurrenceRepository $occurrence_repository,
    DigestBuilder $digest_builder,
    DigestStateRepository $digest_state_repository,
    LoggerChannelInterface $logger,
    MailManagerInterface $mail_manager
  ) {
    $this->configService = $config_service;
    $this->groupRepository = $group_repository;
    $this->occurrenceRepository = $occurrence_repository;
    $this->digestBuilder = $digest_builder;
    $this->digestStateRepository = $digest_state_repository;
    $this->logger = $logger;
    $this->mailManager = $mail_manager;
  }

  /**
   * Build and send the digest email.
   */
  public function sendDigest(bool $force_send = FALSE): array {
    $recipients = $this->configService->getEmailRecipients();
    if (empty($recipients)) {
      throw new \Exception('No email recipients configured for Log Watcher.');
    }

    // Collect error groups to include.
    $max_groups = $this->configService->getMaxGroupsPerEmail();
    $groups = $this->groupRepository->getActiveGroups(
      NULL,
      NULL,
      ['new', 'active'],
      $max_groups,
      0
    );

    // If no groups and we're configured to not send empty digests, return.
    if (empty($groups) && !$this->configService->sendNoErrorDigest() && !$force_send) {
      return [
        'skipped' => TRUE,
        'reason' => 'no_qualifying_errors',
      ];
    }

    $total_groups = $this->groupRepository->countGroups(['new', 'active']);
    $total_occurrences = $this->groupRepository->getTotalOccurrences(['new', 'active']);

    // Build the digest.
    $subject = $this->digestBuilder->buildSubject(count($groups), $total_groups);
    $body_text = $this->digestBuilder->buildBodyText($groups, $total_groups, $total_occurrences);
    $body_html = $this->digestBuilder->buildBodyHtml($groups, $total_groups, $total_occurrences);

    // Send to each recipient.
    $sent = 0;
    foreach ($recipients as $recipient) {
      try {
        $this->sendToRecipient($recipient, $subject, $body_text, $body_html);
        $sent++;
      } catch (\Exception $e) {
        $this->logger->error('Failed to send Log Watcher digest to @recipient: @message', [
          '@recipient' => $recipient,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if ($sent === 0) {
      throw new \Exception('Failed to send digest to any recipients.');
    }

    // Record successful send.
    $this->digestStateRepository->recordSent($subject, $body_text, $body_html);

    return [
      'skipped' => FALSE,
      'subject' => $subject,
      'body_text' => $body_text,
      'body_html' => $body_html,
      'sent_count' => $sent,
      'group_count' => count($groups),
    ];
  }

  /**
   * Send digest to a single recipient.
   */
  protected function sendToRecipient(string $recipient, string $subject, string $body_text, string $body_html): void {
    $module = 'log_watcher';
    $key = 'digest';

    $params = [
      'subject' => $subject,
      'body' => $body_text,
      'body_html' => $body_html,
    ];

    // Build the message.
    $message = $this->mailManager->mail($module, $key, $recipient, NULL, $params);

    if (!$message['result']) {
      throw new \Exception("Mail delivery failed for $recipient");
    }
  }

}



