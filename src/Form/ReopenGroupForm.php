<?php

namespace Drupal\log_watcher\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\log_watcher\Repository\ErrorGroupRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form to reopen a resolved error group.
 */
class ReopenGroupForm extends ConfirmFormBase {

  protected int $groupId;

  /**
   * The error group repository.
   */
  protected ErrorGroupRepository $repo;

  /**
   * Constructs the reopen form.
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
    return 'log_watcher_reopen_group_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Reopen error group?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The error group will be marked as active and included in monitoring again.');
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

    $this->messenger()->addMessage($this->t('Error group reopened and marked as active.'));
    $form_state->setRedirectUrl(new Url('log_watcher.dashboard'));
  }

}

