<?php

namespace digitalistse\BehatTools\Context;

use Drupal\DrupalExtension\Context\RawDrupalContext;

/**
 * Class QueueContext.
 *
 * @package behat\features\bootstrap
 */
class QueueContext extends RawDrupalContext {

  /**
   * @Given /^I delete all items from the "([^"]*)" queue$/
   *
   * @throws \Exception
   */
  public function iDeleteAllItemsFromTheQueue($queue_name) {
    $queue = \Drupal::queue($queue_name);
    if ($queue) {
      $queue->deleteQueue();
      $this->iShouldHaveItemInTheQueue(0, $queue_name);
    }
    else {
      throw new Exception("The queue $queue_name does not exist");
    }
  }

  /**
   * @Then /^I should have the following content in the "([^"]*)" queue:$/
   */
  public function iShouldHaveTheFollowingContentInTheQueue($queue_name, \Behat\Gherkin\Node\PyStringNode $string) {
    if ($queue_ui = \Drupal::service('plugin.manager.queue_ui')
      ->fromQueueName($queue_name)) {

      foreach ($queue_ui->getItems($queue_name) as $item) {
        $queue_item = $queue_ui->loadItem($item->item_id);
        $queue_data = json_encode(unserialize($queue_item->data));
        $expected_data = $string->getRaw();
        if ($queue_data !== $expected_data) {
          throw new Exception('The expected data is not in the queue: ' . $queue_data);
        }
      }
      if (!isset($item)) {
        throw new Exception("The queue $queue_name is empty");
      }
    }
    else {
      throw new Exception("The queue $queue_name does not exist");
    }
  }

  /**
   * @Then /^I should not have the following content in the "([^"]*)" queue:$/
   */
  public function iShouldNotHaveTheFollowingContentInTheQueue($queue_name, \Behat\Gherkin\Node\PyStringNode $string) {
    if ($queue_ui = \Drupal::service('plugin.manager.queue_ui')
      ->fromQueueName($queue_name)) {

      foreach ($queue_ui->getItems($queue_name) as $item) {
        $queue_item = $queue_ui->loadItem($item->item_id);
        $queue_data = json_encode(unserialize($queue_item->data));
        $expected_data = $string->getRaw();
        if ($queue_data === $expected_data) {
          throw new Exception('The expected data is in the queue: ' . $queue_data);
        }
      }
      if (!isset($item)) {
        throw new Exception("The queue $queue_name is empty");
      }
    }
    else {
      throw new Exception("The queue $queue_name does not exist");
    }
  }

  /**
   * @Then /^I should have "([^"]*)" item in the "([^"]*)" queue$/
   * @Then /^I should have "([^"]*)" items in the "([^"]*)" queue$/
   */
  public function iShouldHaveItemInTheQueue($expected_number, $queue_name) {
    /** @var \Drupal\Core\Queue\QueueInterface $queue */
    $queue = \Drupal::service('queue')->get($queue_name);
    if ($queue) {
      $number_of_items = (int) $queue->numberOfItems();
      if ($number_of_items !== (int) $expected_number) {
        throw new Exception("The queue $queue_name has $number_of_items items and it should have $expected_number");
      }
    }
    else {
      throw new Exception("The queue $queue_name does not exist");
    }
  }

}
