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
        // In Drupal 11, deleting users or nodes directly can throw
        // EntityStorageException("Default revision can not be deleted").
        // For users: pre-delete authored nodes and their paragraphs first.
        // For nodes: pre-delete referenced paragraph entities first.
        if ($entity_type === 'user') {
          $nids = \Drupal::entityQuery('node')
            ->condition('uid', $entity->id())
            ->accessCheck(FALSE)
            ->execute();
          if ($nids) {
            $node_storage = \Drupal::entityTypeManager()->getStorage('node');
            foreach ($node_storage->loadMultiple($nids) as $node) {
              $this->deleteNodeParagraphs($node);
              $node->delete();
            }
          }
          try {
            $entity->delete();
          }
          catch (\Drupal\Core\Entity\EntityStorageException $e) {
            if (strpos($e->getMessage(), 'Default revision can not be deleted') === FALSE) {
              throw $e;
            }
            user_cancel([], $entity->id(), 'user_cancel_reassign');
            $batch = &batch_get();
            if (!empty($batch['sets'])) {
              $batch['progressive'] = FALSE;
              batch_process();
            }
          }
        }
        elseif ($entity_type === 'node') {
          $this->deleteNodeParagraphs($entity);
          $entity->delete();
        }
        else {
          $entity->delete();
        }
      }
    }
  }

  /**
   * Pre-deletes paragraph entities referenced by a node to avoid D11
   * EntityReferenceRevisionsOrphanPurger "Default revision can not be deleted".
   */
  private function deleteNodeParagraphs($node): void {
    foreach ($node->getFieldDefinitions() as $field_name => $field_def) {
      if ($field_def->getType() === 'entity_reference_revisions'
        && $field_def->getSetting('target_type') === 'paragraph') {
        foreach ($node->get($field_name)->referencedEntities() as $paragraph) {
          try { $paragraph->delete(); } catch (\Exception $e) {}
        }
      }
    }
  }

}
