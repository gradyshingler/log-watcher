<?php

namespace Drupal\log_watcher\Service;

use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Config service variant that injects the email validator.
 *
 * This avoids the service-locator usage in the original ConfigService file
 * without changing its public API for the rest of the module.
 */
class EmailValidatedConfigService extends ConfigService {

  /**
   * The email validator.
   */
  protected EmailValidator $emailValidator;

  /**
   * Constructs a new config service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EmailValidator $email_validator) {
    parent::__construct($config_factory);
    $this->emailValidator = $email_validator;
  }

  /**
   * {@inheritdoc}
   */
  public function getEmailRecipients(): array {
    $recipients_text = $this->getConfig('email_recipients') ?? '';
    $recipients = [];

    if ($recipients_text) {
      $lines = array_filter(array_map('trim', explode("\n", $recipients_text)));
      foreach ($lines as $line) {
        if ($this->emailValidator->isValid($line)) {
          $recipients[] = $line;
        }
      }
    }

    return $recipients;
  }

}


