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
      throw new \Exception("The queue $queue_name does not exist");
    }
  }

  /**
   * @Then /^The queue "([^"]*)" should contain the following content:$/
   */
  public function theQueueShouldContainTheFollowingContent($queue_name, \Behat\Gherkin\Node\PyStringNode $string) {
    $this->checkContentInTheQueue($queue_name, $string, 'contains');
  }

  /**
   * @Then /^The queue "([^"]*)" should not contain the following content:$/
   */
  public function theQueueShouldNotContainTheFollowingContent($queue_name, \Behat\Gherkin\Node\PyStringNode $string) {
    $this->checkContentInTheQueue($queue_name, $string, 'not_contains');
  }

  /**
   * @Then /^I should not have the following content in the "([^"]*)" queue:$/
   * @Then /^The queue "([^"]*)" should not match exactly the following content:$/
   */
  public function theQueueShouldNotMatchExacltyTheFollowingContent($queue_name, \Behat\Gherkin\Node\PyStringNode $string) {
    $this->checkContentInTheQueue($queue_name, $string, 'not_equal');
  }

  /**
   * @Then /^I should have the following content in the "([^"]*)" queue:$/
   * @Then /^The queue "([^"]*)" should match exactly the following content:$/
   */
  public function theQueueShouldMatchExacltyTheFollowingContent($queue_name, \Behat\Gherkin\Node\PyStringNode $string) {
    $this->checkContentInTheQueue($queue_name, $string, 'equal');
  }

  private function checkContentInTheQueue($queue_name, $string, $type) {
    $queue_ui = \Drupal::service('plugin.manager.queue_ui')
      ->fromQueueName($queue_name);
    if (!$queue_ui) {
      throw new \Exception("The queue $queue_name does not exist");
    }
    foreach ($queue_ui->getItems($queue_name) as $item) {
      $queue_item = $queue_ui->loadItem($item->item_id);
      $queue_data = json_encode(unserialize($queue_item->data));
      $expected_data = $string->getRaw();
      switch ($type) {
        case 'equal':
          if ($queue_data !== $expected_data) {
            throw new \Exception('The expected data is not exaclty the same as the queue: ' . $queue_data);
          }
          break;

        case 'not_equal':
          if ($queue_data === $expected_data) {
            throw new \Exception('The expected data is exactly the same in the queue: ' . $queue_data);
          }
          break;

        case 'contains':
          if (strpos($queue_data, $expected_data) === FALSE) {
            throw new \Exception('The expected data is not in any part of the queue: ' . $queue_data);
          }
          break;

        case 'not_contains':
          if (strpos($queue_data, $expected_data) !== FALSE) {
            throw new \Exception('The expected data is not in any part of the queue: ' . $queue_data);
          }
          break;

        default:
          throw new \Exception("This type ($type) is not defined");
      }
    }
    if (!isset($item)) {
      throw new \Exception("The queue $queue_name is empty");
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
        throw new \Exception("The queue $queue_name has $number_of_items items and it should have $expected_number");
      }
    }
    else {
      throw new \Exception("The queue $queue_name does not exist");
    }
  }

}
