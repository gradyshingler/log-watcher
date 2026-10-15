<?php

namespace Drupal\log_watcher\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\log_watcher\Repository\ErrorGroupRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form to delete all error groups for a status.
 */
class DeleteGroupsByStatusForm extends ConfirmFormBase {

  /**
   * The status being cleared.
   */
  protected string $status = '';

  /**
   * The error group repository.
   */
  protected ErrorGroupRepository $repo;

  /**
   * Number of groups available to delete.
   */
  protected int $groupCount = 0;

  /**
   * Constructs the delete-by-status form.
   */
  public function __construct(ErrorGroupRepository $repo) {
    $this->repo = $repo;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $container->get('log_watcher.error_group_repository');

    return new static($repo);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'log_watcher_delete_groups_by_status_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $status = NULL) {
    if (!in_array($status, ['resolved', 'ignored'], TRUE)) {
      throw new NotFoundHttpException('Invalid status.');
    }

    $this->status = $status;
    $this->groupCount = $this->repo->countGroups($status);

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->status === 'resolved'
      ? $this->t('Delete all @count resolved error groups?', ['@count' => $this->groupCount])
      : $this->t('Delete all @count ignored error groups?', ['@count' => $this->groupCount]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->status === 'resolved'
      ? $this->t('This will permanently delete all resolved error groups and their occurrences. This action cannot be undone.')
      : $this->t('This will permanently delete all ignored error groups and their occurrences. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url($this->status === 'resolved' ? 'log_watcher.dashboard_resolved' : 'log_watcher.dashboard_ignored');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $deleted = $this->repo->deleteGroupsByStatus($this->status);

    $this->messenger()->addStatus($this->status === 'resolved'
      ? $this->t('Deleted @count resolved error groups.', ['@count' => $deleted])
      : $this->t('Deleted @count ignored error groups.', ['@count' => $deleted]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}


