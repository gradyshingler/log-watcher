<?php

namespace Drupal\Tests\log_watcher\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for Log Watcher database operations.
 *
 * @group log_watcher
 */
class LogWatcherKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['log_watcher'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('log_watcher', [
      'log_watcher_error_groups',
      'log_watcher_occurrences',
      'log_watcher_digest_state',
    ]);
  }

  /**
   * Test that error group creation works.
   */
  public function testErrorGroupCreation() {
    /** @var \Drupal\log_watcher\Service\ErrorGroupService $service */
    $service = $this->container->get('log_watcher.error_group_service');

    $group = $service->findOrCreateAndIncrement('test_channel', 3, 'Test error message', []);

    $this->assertNotNull($group);
    $this->assertEquals('test_channel', $group['channel']);
    $this->assertEquals(3, $group['severity']);
    $this->assertEquals(1, $group['occurrence_count']);
  }

  /**
   * Test that duplicate errors increment the group.
   */
  public function testDuplicateErrorIncrementsGroup() {
    /** @var \Drupal\log_watcher\Service\ErrorGroupService $service */
    $service = $this->container->get('log_watcher.error_group_service');

    $group1 = $service->findOrCreateAndIncrement('test_channel', 3, 'Test error', []);
    $group2 = $service->findOrCreateAndIncrement('test_channel', 3, 'Test error', []);

    $this->assertEquals($group1['id'], $group2['id'], 'Duplicate errors should reference same group');
    $this->assertEquals(1, $group1['occurrence_count']);
    $this->assertEquals(2, $group2['occurrence_count']);
  }

  /**
   * Test error grouping repository methods.
   */
  public function testErrorGroupRepository() {
    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');

    // Create some test groups.
    $connection = $this->container->get('database');
    $now = time();

    for ($i = 0; $i < 5; $i++) {
      $connection->insert('log_watcher_error_groups')->fields([
        'fingerprint' => 'fp_' . $i,
        'channel' => 'test',
        'severity' => $i,
        'status' => 'active',
        'message' => 'Error ' . $i,
        'occurrence_count' => $i + 1,
        'first_seen' => $now,
        'last_seen' => $now,
        'created' => $now,
        'updated' => $now,
      ])->execute();
    }

    $count = $repo->countGroups();
    $this->assertEquals(5, $count, 'Should count 5 groups');

    $active = $repo->getActiveGroups(NULL, NULL, 'active', 100, 0);
    $this->assertEquals(5, count($active), 'Should return all active groups');
  }

  /**
   * Test status updates.
   */
  public function testStatusUpdate() {
    /** @var \Drupal\log_watcher\Repository\ErrorGroupRepository $repo */
    $repo = $this->container->get('log_watcher.error_group_repository');
    /** @var \Drupal\log_watcher\Service\ErrorGroupService $service */
    $service = $this->container->get('log_watcher.error_group_service');

    $group = $service->findOrCreateAndIncrement('test_channel', 3, 'Test error', []);

    $result = $repo->updateStatus($group['id'], 'resolved');
    $this->assertTrue($result, 'Status update should succeed');

    $updated = $repo->getGroupById($group['id']);
    $this->assertEquals('resolved', $updated['status'], 'Status should be updated');
  }

}

