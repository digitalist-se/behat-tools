<?php

namespace digitalistse\BehatTools\Context;

use Behat\Gherkin\Node\TableNode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\DrupalExtension\Context\RawDrupalContext;
use Behat\Behat\Context\SnippetAcceptingContext;

/**
 * Defines application features from the specific context.
 */
class CleanUpContext extends RawDrupalContext {

  private EntityTypeManagerInterface $entityTypeManager;

  public function __construct() {
    $this->entityTypeManager = \Drupal::entityTypeManager();
  }

  /**
   * Given I delete the content of the entity "user" with the properties:
   * | mail                |
   * | license-01@test.com |
   * | license-02@test.com |
   *
   * @Given /^I delete the content of the entity "([^"]*)" with the properties:$/
   */
  public function iDeleteTheContentOfTheEntityWithTheProperties($entity_type, TableNode $table) {
    $rows = $table->getHash();
    foreach ($rows as $row) {
      $entities = $this->entityTypeManager->getStorage($entity_type)
        ->loadByProperties($row);
      foreach ($entities as $entity) {
        $entity->delete();
      }
    }
  }

}
