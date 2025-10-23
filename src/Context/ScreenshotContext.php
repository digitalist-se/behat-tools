<?php

namespace digitalistse\BehatTools\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Drupal\Core\File\FileSystemInterface;
use Drupal\DrupalExtension\Context\RawDrupalContext;

/**
 * Screenshot context that saves desktop and mobile screenshots
 * into separate folders for easy comparison.
 */
class ScreenshotContext extends RawDrupalContext implements Context {

  const SCREENSHOT_EXTENSION = 'png';

  /**
   * The current feature filename (without extension).
   *
   * @var string
   */
  private string $currentFeature;

  /**
   * Base path where screenshots are stored.
   *
   * @var string
   */
  private string $screenshotBasePath;

  /**
   * Subfolder name for desktop screenshots.
   *
   * @var string
   */
  private string $desktopSubfolder = 'desktop';

  /**
   * Subfolder name for mobile screenshots.
   *
   * @var string
   */
  private string $mobileSubfolder = 'mobile';

  /**
   * Desktop viewport size.
   *
   * @var array{width:int,height:int}
   */
  private array $desktopSize = [
    'width' => 1920,
    'height' => 1080,
  ];

  /**
   * Mobile devices map: deviceName => [width:int, height:int].
   *
   * @var array<string, array{width:int,height:int}>
   */
  private array $mobileDevices = [];

  /**
   * @BeforeScenario
   */
  public function prepare(BeforeScenarioScope $scope) {
    $this->currentFeature = pathinfo($scope->getFeature()->getFile(), PATHINFO_FILENAME);
    $settings = $scope->getEnvironment()->getSuite()->getSettings();

    // Required base path.
    $this->screenshotBasePath = $settings['screenshot_context']['screenshot_path'];

    // Optional configuration overrides.
    if (!empty($settings['screenshot_context']['desktop_subfolder'])) {
      $this->desktopSubfolder = $settings['screenshot_context']['desktop_subfolder'];
    }
    if (!empty($settings['screenshot_context']['mobile_subfolder'])) {
      $this->mobileSubfolder = $settings['screenshot_context']['mobile_subfolder'];
    }
    if (!empty($settings['screenshot_context']['desktop_size'])) {
      $this->desktopSize = $settings['screenshot_context']['desktop_size'];
    }
    if (!empty($settings['screenshot_context']['mobile_devices'])) {
      $this->mobileDevices = $settings['screenshot_context']['mobile_devices'];
    }

    // Backwards-compat: if only display_sizes provided, try to infer.
    if (empty($this->mobileDevices) && !empty($settings['screenshot_context']['display_sizes'])) {
      $displaySizes = $settings['screenshot_context']['display_sizes'];
      if (!empty($displaySizes['desktop'])) {
        $this->desktopSize = $displaySizes['desktop'];
      }
      if (!empty($displaySizes['mobile'])) {
        $this->mobileDevices = ['mobile' => $displaySizes['mobile']];
      }
    }
  }

  /**
   * @Then /^take a screenshot with name "([^"]*)"$/
   */
  public function takeAScreenshotWithName($name) {
    // Ensure folders exist.
    $desktopDir = $this->screenshotBasePath . '/' . $this->desktopSubfolder;
    $mobileDir = $this->screenshotBasePath . '/' . $this->mobileSubfolder;

    \Drupal::service('file_system')->prepareDirectory($desktopDir, FileSystemInterface::CREATE_DIRECTORY);
    \Drupal::service('file_system')->prepareDirectory($mobileDir, FileSystemInterface::CREATE_DIRECTORY);

    $session = $this->getSession();
    $driver = $session->getDriver();

    // Desktop screenshot.
    $driver->resizeWindow($this->desktopSize['width'], $this->desktopSize['height'], 'current');
    $desktopFilename = $desktopDir . '/' . $this->currentFeature . '-' . $name . '.' . self::SCREENSHOT_EXTENSION;
    file_put_contents($desktopFilename, $driver->getScreenshot());

    // Mobile screenshots per device.
    foreach ($this->mobileDevices as $deviceName => $dimensions) {
      $driver->resizeWindow($dimensions['width'], $dimensions['height'], 'current');
      $mobileFilename = $mobileDir . '/' . $this->currentFeature . '-' . $name . '-' . $deviceName . '.' . self::SCREENSHOT_EXTENSION;
      file_put_contents($mobileFilename, $driver->getScreenshot());
    }

    // Restore desktop size.
    $session->resizeWindow($this->desktopSize['width'], $this->desktopSize['height'], 'current');
  }

  /**
   * Take a full-page screenshot with optional device type filter.
   *
   * Allows you to specify which device types to capture, making it flexible
   * and future-proof for any device configuration in behat.yml.
   *
   * Usage:
   *   - take a full-page screenshot with name "my-screenshot"
   *     (captures all: desktop + all mobile_devices)
   *   - take a full-page screenshot with name "my-screenshot" for "desktop"
   *     (only desktop)
   *   - take a full-page screenshot with name "my-screenshot" for "mobile"
   *     (only mobile devices, no desktop)
   *   - take a full-page screenshot with name "my-screenshot" for "desktop,mobile"
   *     (desktop + mobile)
   *
   * @Then /^take a full-page screenshot with name "([^"]*)"(?: for "([^"]*)")?$/
   */
  public function takeAFullPageScreenshotWithName($name, $deviceTypes = NULL) {
    // Parse device types filter (if provided)
    $captureDesktop = TRUE;
    $captureMobile = TRUE;

    if ($deviceTypes !== NULL) {
      $types = array_map('trim', explode(',', strtolower($deviceTypes)));
      $captureDesktop = in_array('desktop', $types);
      $captureMobile = in_array('mobile', $types);
    }

    $session = $this->getSession();
    $driver = $session->getDriver();

    // Helper to capture full page for a given size and target directory.
    $captureFullPage = function (int $width, int $height, string $targetDir, string $filenamePrefix) use ($session, $driver) {
      \Drupal::service('file_system')->prepareDirectory($targetDir, FileSystemInterface::CREATE_DIRECTORY);
      $driver->resizeWindow($width, $height, 'current');

      $originalScrollPosition = $session->evaluateScript('return window.pageYOffset || document.documentElement.scrollTop');
      $pageHeight = $session->evaluateScript('return document.body.scrollHeight');
      $viewportHeight = $session->evaluateScript('return window.innerHeight');

      $scrollPosition = 0;
      $segment = 0;

      while ($scrollPosition < $pageHeight) {
        $session->executeScript("window.scrollTo(0, {$scrollPosition});");
        usleep(500000); // allow lazy-loaded content
        $filename = $targetDir . '/' . $filenamePrefix . '-segment-' . $segment . '.' . self::SCREENSHOT_EXTENSION;
        file_put_contents($filename, $driver->getScreenshot());
        $scrollPosition += $viewportHeight;
        $segment++;
      }

      $session->executeScript("window.scrollTo(0, {$originalScrollPosition});");
    };

    $captured = [];

    // Desktop full-page screenshot
    if ($captureDesktop) {
      $desktopDir = $this->screenshotBasePath . '/' . $this->desktopSubfolder;
      $captureFullPage(
        $this->desktopSize['width'],
        $this->desktopSize['height'],
        $desktopDir,
        $this->currentFeature . '-' . $name
      );
      $captured[] = 'desktop';
    }

    // Mobile screenshots
    if ($captureMobile && !empty($this->mobileDevices)) {
      $mobileDir = $this->screenshotBasePath . '/' . $this->mobileSubfolder;
      foreach ($this->mobileDevices as $deviceName => $dimensions) {
        $captureFullPage(
          $dimensions['width'],
          $dimensions['height'],
          $mobileDir,
          $this->currentFeature . '-' . $name . '-' . $deviceName
        );
      }
      $captured[] = 'mobile';
    }

    // Restore desktop size.
    $session->resizeWindow($this->desktopSize['width'], $this->desktopSize['height'], 'current');

    $capturedTypes = !empty($captured) ? implode(', ', $captured) : 'none';
    echo "Screenshot saved: {$name} (captured: {$capturedTypes})\n";
  }
}


