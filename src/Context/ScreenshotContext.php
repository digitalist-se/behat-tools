<?php

namespace digitalistse\BehatTools\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Drupal\Core\File\FileSystemInterface;
use Drupal\DrupalExtension\Context\RawDrupalContext;

/**
 * Defines application features from the specific context.
 */
class ScreenshotContext extends RawDrupalContext implements Context {

  const SCREENSHOT_EXTENSION = 'png';

  private bool $doResizing = FALSE;

  private array $displaySizes = [];

  private string $currentFeature;

  private string $screenshotPath;

  /**
   * @BeforeScenario
   */
  public function prepare(BeforeScenarioScope $scope) {
    $this->currentFeature = pathinfo($scope->getFeature()->getFile(), PATHINFO_FILENAME);
    $settings = $scope->getEnvironment()->getSuite()->getSettings();
    $this->screenshotPath = $settings['screenshot_context']['screenshot_path'];
    $this->doResizing = $settings['screenshot_context']['do_resizing'];
    $this->displaySizes = $settings['screenshot_context']['display_sizes'];
  }

  /**
   * @Then /^take a screenshot with name "([^"]*)"$/
   */
  public function takeAScreenshotWithName($name) {
    \Drupal::service('file_system')->prepareDirectory($this->screenshotPath, FileSystemInterface::CREATE_DIRECTORY);
    if ($this->doResizing) {
      $this->takeScreenshotsWithDifferentSizes($name);
    }
    else {
      $filename = $this->screenshotPath . '/' . $this->currentFeature . '-' . $name . '.' . self::SCREENSHOT_EXTENSION;
      file_put_contents($filename, $this->getSession()
        ->getDriver()
        ->getScreenshot());
    }
  }

  private function takeScreenshotsWithDifferentSizes($name) {
    foreach ($this->displaySizes as $size => $dimensions) {
      $filename = $this->screenshotPath . '/' . $this->currentFeature . '-' . $name . '-' . $size . '.' . self::SCREENSHOT_EXTENSION;
      $this->getSession()
        ->getDriver()
        ->resizeWindow($dimensions['width'], $dimensions['height'], 'current');
      file_put_contents($filename, $this->getSession()
        ->getDriver()
        ->getScreenshot());
    }
    $this->getSession()->resizeWindow(1980, 1080, 'current');
  }

  /**
   * @Then /^take a full-page screenshot with name "([^"]*)"$/
   */
  public function takeAFullPageScreenshotWithName($name) {
    \Drupal::service('file_system')->prepareDirectory($this->screenshotPath, FileSystemInterface::CREATE_DIRECTORY);

    $session = $this->getSession();
    $driver = $session->getDriver();

    // Get page height and viewport height using JavaScript
    $pageHeight = $session->evaluateScript('return document.body.scrollHeight');
    $viewportHeight = $session->evaluateScript('return window.innerHeight');

    $scrollPosition = 0;
    $segment = 0; // For naming each screenshot segment

    // Loop to capture screenshots in segments
    while ($scrollPosition < $pageHeight) {
      // Scroll to the current position
      $session->executeScript("window.scrollTo(0, {$scrollPosition});");

      // Optional: Pause to allow lazy-loading elements to render
      usleep(500000); // 500ms pause

      // Capture screenshot for the current segment
      $filename = $this->screenshotPath . '/' . $this->currentFeature . '-' . $name . '-segment-' . $segment . '.' . self::SCREENSHOT_EXTENSION;
      file_put_contents($filename, $driver->getScreenshot());

      // Move to the next section
      $scrollPosition += $viewportHeight;
      $segment++;
    }

    // Restore window size after taking the full-page screenshot
    $this->getSession()->resizeWindow(1980, 1080, 'current');
  }

}
