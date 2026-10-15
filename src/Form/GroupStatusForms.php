<?php

namespace Drupal\log_watcher\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form to resolve an error group.
 */
class ResolveGroupForm extends ConfirmFormBase {

  protected int $groupId;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'log_watcher_resolve_group_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Mark error group as resolved?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The error group will be marked as resolved and excluded from active monitoring.');
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

    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    $group = $repo->getGroupById($error_group_id);

    if (!$group) {
      throw new NotFoundHttpException('Error group not found.');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    $repo->updateStatus($this->groupId, 'resolved');

    $this->messenger()->addMessage($this->t('Error group marked as resolved.'));
    $form_state->setRedirectUrl(new Url('log_watcher.dashboard'));
  }

}

/**
 * Form to reopen a resolved error group.
 */
class ReopenGroupForm extends ConfirmFormBase {

  protected int $groupId;

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

    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    $group = $repo->getGroupById($error_group_id);

    if (!$group) {
      throw new NotFoundHttpException('Error group not found.');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    $repo->updateStatus($this->groupId, 'active');

    $this->messenger()->addMessage($this->t('Error group reopened and marked as active.'));
    $form_state->setRedirectUrl(new Url('log_watcher.dashboard'));
  }

}

/**
 * Form to ignore an error group.
 */
class IgnoreGroupForm extends ConfirmFormBase {

  protected int $groupId;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'log_watcher_ignore_group_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Ignore error group?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The error group will be ignored. It will continue to be counted but will not trigger email notifications.');
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

    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    $group = $repo->getGroupById($error_group_id);

    if (!$group) {
      throw new NotFoundHttpException('Error group not found.');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    $repo->updateStatus($this->groupId, 'ignored');

    $this->messenger()->addMessage($this->t('Error group ignored.'));
    $form_state->setRedirectUrl(new Url('log_watcher.dashboard'));
  }

}

/**
 * Form to stop ignoring an error group.
 */
class UnignoreGroupForm extends ConfirmFormBase {

  protected int $groupId;

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

    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    $group = $repo->getGroupById($error_group_id);

    if (!$group) {
      throw new NotFoundHttpException('Error group not found.');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    $repo->updateStatus($this->groupId, 'active');

    $this->messenger()->addMessage($this->t('Error group is no longer ignored.'));
    $form_state->setRedirectUrl(new Url('log_watcher.dashboard'));
  }

}

/**
 * Form to delete an error group.
 */
class DeleteGroupForm extends ConfirmFormBase {

  protected int $groupId;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'log_watcher_delete_group_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Delete error group?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action cannot be undone. The error group and all its recorded occurrences will be permanently deleted.');
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

    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    $group = $repo->getGroupById($error_group_id);

    if (!$group) {
      throw new NotFoundHttpException('Error group not found.');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    $repo->delete($this->groupId);

    $this->messenger()->addMessage($this->t('Error group deleted.'));
    $form_state->setRedirectUrl(new Url('log_watcher.dashboard'));
  }

}

