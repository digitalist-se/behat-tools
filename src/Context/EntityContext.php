<?php

namespace digitalistse\BehatTools\Context;

use Behat\Gherkin\Node\TableNode;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\DrupalExtension\Context\RawDrupalContext;

/**
 * Defines application features from the specific context.
 */
class EntityContext extends RawDrupalContext {

  private static $datetimeFormat;

  /**
   * @BeforeSuite
   */
  public static function setupDatetimeFormat(BeforeSuiteScope $scope) {
    $environment = $scope->getEnvironment();
    $suite = $environment->getSuite();
    $settings = $suite->getSettings();
    if (isset($settings['parameters']['entity_context']['datetime_format'])) {
      self::$datetimeFormat = $settings['parameters']['entity_context']['datetime_format'];
    }
  }

  private EntityTypeManagerInterface $entityTypeManager;

  private EntityFieldManagerInterface $entityFieldManager;

  public function __construct() {
    $this->entityTypeManager = \Drupal::entityTypeManager();
    $this->entityFieldManager = \Drupal::service('entity_field.manager');
  }

  /**
   * Converts a date to the format used in the database.
   */
  private function convertDate($date_to_convert, $format): string {
    $date = new \DateTime($date_to_convert);
    return $date->format($format);
  }

  /**
   * @Given /^a "([^"]*)" entity exists with the properties:$/
   *
   * @param $entity_type
   * @param \Behat\Gherkin\Node\TableNode $table
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function aEntityExistsWithTheProperties($entity_type, TableNode $table) {
    $rows = $table->getHash();
    foreach ($rows as &$row) {
      foreach ($row as $property => $value) {
        if ($value && str_contains($property, ':')) {
          $parts = explode(':', $property);

          // Ensure that there are three parts
          if (count($parts) == 3) {
            $property = $parts[0];
            $referenced_entity_type = $parts[1];
            $referenced_property = $parts[2];
          }
          else {
            throw new \Exception('Invalid property name');
          }
          $referenced_entities = $this->entityTypeManager->getStorage($referenced_entity_type)
            ->loadByProperties([$referenced_property => $value]);
          if (count($referenced_entities) == 1) {
            $referenced_entity = reset($referenced_entities);
            $row[$property] = $referenced_entity->id();
            unset($row[$property . ':' . $referenced_entity_type . ':' . $referenced_property]);
          }
          elseif (count($referenced_entities) == 0) {
            throw new \Exception('There is no content for the referenced entity');
          }
          else {
            throw new \Exception('There are multiple contents for the referenced entity ' . $property . '.' . $referenced_entity_type . '.' . $referenced_property);
          }
        }
        if (isset(self::$datetimeFormat[$entity_type][$property])) {
          $row[$property] = $this->convertDate($value, self::$datetimeFormat[$entity_type][$property]);
        }
      }
      $this->processEntityFields($row, $entity_type);
      $this->entityTypeManager->getStorage($entity_type)
        ->create($row)->save();
    }
  }

  /**
   * @Then /^I go to the "([^"]*)" of the entity "([^"]*)" with these properties:$/
   */
  public function iGoToTheOfTheEntityWithTheseProperties($rel, $entity_type, TableNode $table) {
    $rows = $table->getHash()[0];
    $entities = $this->entityTypeManager->getStorage($entity_type)
      ->loadByProperties($rows);
    if (count($entities) == 1) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = reset($entities);
      $this->visitPath($entity->toUrl($rel)->toString());
    }
    elseif (count($entities) == 0) {
      throw new \Exception('No content found');
    }
    else {
      throw new \Exception('There are multiple entities for the given properties');
    }
  }

  /**
   * @Then /^I check the "([^"]*)" properties based on the first column:$/
   */
  public function iCheckThePropertiesBasedOnTheFirstColumn($entity_type, TableNode $table) {
    $rows = $table->getHash();
    foreach ($rows as $row) {
      // Get the first column where we have to find the entity.
      $compare_entity = array_key_first($row);
      foreach ($row as $property => $value) {
        $this->entityTypeManager->getStorage($entity_type)->resetCache();
        // Find the entity to check.
        if ($property == $compare_entity) {
          $entities = $this->entityTypeManager->getStorage($entity_type)
            ->loadByProperties([$property => $value]);
          if (count($entities) == 1) {
            /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
            $entity = reset($entities);
            continue;
          }
          elseif (count($entities) == 0) {
            throw new \Exception('No content found');
          }
          else {
            throw new \Exception('There are multiple entities for the given properties');
          }
        }
        // Check the properties.

        $value = $entity->get($property)->value;

        $row[$property] = $row[$property] === 'null' ? NULL : $row[$property];
        if (isset(self::$datetimeFormat[$entity_type][$property]) && $row[$property]) {
          $row[$property] = $this->convertDate($row[$property], self::$datetimeFormat[$entity_type][$property]);
        }
        if ($value !== $row[$property]) {
          throw new \Exception($entity_type . ' property is not the expected one. ' . $entity_type . ' id: ' . $entity->id() . ' Property ' . $property . ': Actual value: ' . $value . ' Expected value: ' . $row[$property]);
        }
      }
    }
  }

  /**
   * @Then /^The "([^"]*)" date of the "([^"]*)" should be "([^"]*)" from the "([^"]*)" date based on these properties:$/
   */
  public function theDateOfTheShouldBeFromTheDateBasedOnTheseProperties($property, $entity_type, $date_difference, $from_date_property, TableNode $table) {
    $rows = $table->getHash();
    foreach ($rows as $row) {
      $entities = $this->entityTypeManager->getStorage($entity_type)
        ->loadByProperties($row);
      if (count($entities) == 1) {
        /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
        $entity = reset($entities);
        $date = new \DateTime($entity->get($from_date_property)->value);
        $date->modify($date_difference);
        if ($date->format(self::$datetimeFormat[$entity_type][$property]) !== $entity->get($property)->value) {
          throw new \Exception($entity_type . ' property is not the expected one. ' . $entity_type . ' id: ' . $entity->id() . ' Property ' . $property . ': Actual value: ' . $entity->get($property)->value . ' Expected value: ' . $date->format(self::$datetimeFormat[$entity_type][$property]));
        }
      }
      elseif (count($entities) == 0) {
        throw new \Exception('No content found');
      }
      else {
        throw new \Exception('There are multiple entities for the given properties');
      }
    }
  }

  /**
   * @Given /^I update the "([^"]*)" entity based on the first column:$/
   */
  public function iUpdateTheEntityBasedOnTheFirstColumn($entity_type, TableNode $table) {
    $rows = $table->getHash();
    foreach ($rows as $row) {
      // Get the first column where we have to find the entity.
      $compare_entity = array_key_first($row);
      foreach ($row as $property => $value) {
        $this->entityTypeManager->getStorage($entity_type)->resetCache();
        // Find the entity to check.
        if ($property == $compare_entity) {
          $entities = $this->entityTypeManager->getStorage($entity_type)
            ->loadByProperties([$property => $value]);
          if (count($entities) == 1) {
            /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
            $entity = reset($entities);
            continue;
          }
          elseif (count($entities) == 0) {
            throw new \Exception('No content found');
          }
          else {
            throw new \Exception('There are multiple entities for the given properties');
          }
        }
        // Set the properties.
        $row[$property] = $row[$property] === 'null' ? NULL : $row[$property];
        if (isset(self::$datetimeFormat[$entity_type][$property]) && $row[$property]) {
          $row[$property] = $this->convertDate($row[$property], self::$datetimeFormat[$entity_type][$property]);
        }
        $entity->set($property, $row[$property]);
        $entity->save();
      }
    }
  }

  /**
   * Process and transform entity fields using dot notation.
   *
   * @param array $row
   *   Current row processed.
   * @param string $entity_type
   *   Entity type.
   *
   * @return void
   */
  private function processEntityFields(array &$row, string $entity_type): void {
    foreach ($row as $property => $value) {
      if ($value && preg_match('/^(?:\((?<type>[^)]+)\)\s*)?(?<field_name>[^-.]+)\.(?<field_property>[^-.]+)/', $property, $matches)) {
        $type = $matches['type'] ?? NULL;
        $field_name = $matches['field_name'] ?? NULL;
        $field_property = $matches['field_property'] ?? NULL;

        if (!$field_name) {
          continue;
        }

        switch ($type) {
          case 'daterange':
            $row[$field_name][$field_property] = $this->convertDate($value, self::$datetimeFormat[$entity_type][$field_name]);
            break;
          default:
            $row[$field_name][$field_property] = $value;
        }
      }
    }
  }

}
