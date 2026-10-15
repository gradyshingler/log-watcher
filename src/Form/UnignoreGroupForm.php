<?php

namespace Drupal\log_watcher\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\log_watcher\Repository\ErrorGroupRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form to stop ignoring an error group.
 */
class UnignoreGroupForm extends ConfirmFormBase {

  protected int $groupId;

  /**
   * The error group repository.
   */
  protected ErrorGroupRepository $repo;

  /**
   * Constructs the unignore form.
   */
  public function __construct(ErrorGroupRepository $repo) {
    $this->repo = $repo;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('log_watcher.error_group_repository'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'log_watcher_unignore_group_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Stop ignoring error group?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The error group will be marked as active again and included in email notifications.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('log_watcher.group_detail', ['error_group_id' => $this->groupId]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $error_group_id = NULL) {
    $this->groupId = $error_group_id;

    $group = $this->repo->getGroupById($error_group_id);

    if (!$group) {
      throw new NotFoundHttpException('Error group not found.');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->repo->updateStatus($this->groupId, 'active');

    $this->messenger()->addMessage($this->t('Error group is no longer ignored.'));
    $form_state->setRedirectUrl(new Url('log_watcher.dashboard'));
  }

}

