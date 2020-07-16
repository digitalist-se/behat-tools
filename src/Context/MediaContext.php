<?php

namespace digitalistse\BehatTools\Context;

use Drupal\Component\Utility\Random;
use Drupal\DrupalExtension\Context\RawDrupalContext;
use Drupal\media\Entity\Media;

/**
 * Class ParagraphsContext.
 *
 * @package behat\features\bootstrap
 */
class MediaContext extends RawDrupalContext {

  /**
   * @var bool|\stdClass|\stdClass[]
   */
  public $mediaNames;

  /**
   * Generates random images, saved as media items.
   *
   * @param int $n
   *   (optional) How many to generate. Defaults to 1.
   * @param string $name
   *   (optional) The label of the media item wrapping the image. Defaults to
   *   a random string.
   * @param string $bundle
   *   (optional) The bundle of the media entity.
   * @param string $alt
   *   (optional) The alt text for the image.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @Given a random image
   * @Given a random image named :name
   * @Given a random image named :name of bundle :bundle
   * @Given a random image with alt text :alt
   * @Given a random image named :name with alt text :alt
   * @Given :count random images
   */
  public function randomImage($n = 1, $name = NULL, $bundle = 'image', $alt = NULL) {
    $random = new Random();

    for ($i = 0; $i < $n; $i++) {
      $uri = $random->image(uniqid('public://random_') . '.png', '240x240', '640x480');

      $file = \Drupal\file\Entity\File::create([
        'uri' => $uri,
      ]);
      $file->setMimeType('image/png');
      $file->setTemporary();
      $file->save();

      $media = Media::create([
        'bundle' => $bundle,
        'name' => $name ?: $random->name(32),
        'image' => $file->id(),
        'field_media_in_library' => TRUE,
      ]);
      if ($alt) {
        $media->image->alt = $alt;
      }
      $media->setPublished();
      $media->save();
      $this->mediaNames[$name] = $media->id();
    }
  }

}
