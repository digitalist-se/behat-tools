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
   * JS condition polled until the page has visually settled.
   *
   * Waiting only for <img> elements and fonts is not enough: CSS
   * background images, late-rendering form sections (vertical tabs,
   * editors) and Leaflet map controls all paint after that, which makes
   * screenshot comparisons flaky. This condition requires: document
   * ready, two animation frames painted since the wait was armed, fonts
   * loaded, in-viewport images complete, visible Leaflet maps showing
   * their attribution (an empty attribution bar is a map still
   * initializing), and the page height identical on two consecutive
   * polls (late-rendering sections change it).
   */
  private const STABLE_RENDER_CONDITION = <<<'JS'
(function () {
  if (document.readyState !== 'complete' || !window.__behatFramesPainted) { return false; }
  if (typeof document.fonts !== 'undefined' && document.fonts && document.fonts.status !== 'loaded') { return false; }
  var vw = window.innerWidth || document.documentElement.clientWidth || 0;
  var vh = window.innerHeight || document.documentElement.clientHeight || 0;
  var imgs = Array.prototype.slice.call(document.images || []);
  for (var i = 0; i < imgs.length; i++) {
    var img = imgs[i];
    if (!img) { continue; }
    var rect = img.getBoundingClientRect();
    var inViewport = rect.bottom > 0 && rect.right > 0 && rect.top < vh && rect.left < vw;
    if (!inViewport) { continue; }
    if (!img.complete || typeof img.naturalWidth === 'undefined' || img.naturalWidth === 0) { return false; }
  }
  var maps = document.querySelectorAll('.leaflet-container');
  for (var j = 0; j < maps.length; j++) {
    if (!maps[j].offsetParent) { continue; }
    var attribution = maps[j].querySelector('.leaflet-control-attribution');
    if (!attribution || attribution.textContent.trim() === '') { return false; }
  }
  var height = document.body.scrollHeight;
  var stable = window.__behatStableHeight === height;
  window.__behatStableHeight = height;
  return stable;
}())
JS;

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
    $this->waitForStableRender();
    $desktopFilename = $desktopDir . '/' . $this->currentFeature . '-' . $name . '.' . self::SCREENSHOT_EXTENSION;
    file_put_contents($desktopFilename, $driver->getScreenshot());

    // Mobile screenshots per device.
    foreach ($this->mobileDevices as $deviceName => $dimensions) {
      $driver->resizeWindow($dimensions['width'], $dimensions['height'], 'current');
      $this->waitForStableRender();
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

      $this->waitForStableRender();

      $originalScrollPosition = $session->evaluateScript('return window.pageYOffset || document.documentElement.scrollTop');
      $viewportHeight = $session->evaluateScript('return window.innerHeight');

      // Pre-scroll through the whole page so lazy content (images, CSS
      // backgrounds, JS-rendered sections) is triggered before any segment
      // is captured; otherwise it can pop in mid-capture and shift the
      // content of later segments.
      $pageHeight = $session->evaluateScript('return document.body.scrollHeight');
      for ($position = 0; $position < $pageHeight; $position += $viewportHeight) {
        $session->executeScript("window.scrollTo(0, {$position});");
        // Give the browser time to paint and fire lazy loaders.
        usleep(150000);
        $pageHeight = $session->evaluateScript('return document.body.scrollHeight');
      }
      $session->executeScript('window.scrollTo(0, 0);');
      $this->waitForStableRender();

      $pageHeight = $session->evaluateScript('return document.body.scrollHeight');
      $scrollPosition = 0;
      $segment = 0;

      while ($scrollPosition < $pageHeight) {
        $session->executeScript("window.scrollTo(0, {$scrollPosition});");
        $this->waitForStableRender();

        $filename = $targetDir . '/' . $filenamePrefix . '-segment-' . $segment . '.' . self::SCREENSHOT_EXTENSION;
        file_put_contents($filename, $driver->getScreenshot());

        // Re-read height: lazy images can grow the page as they load, which
        // would otherwise change the segment count between runs.
        $pageHeight = $session->evaluateScript('return document.body.scrollHeight');
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

  /**
   * Waits until the page has visually settled (see the condition const).
   *
   * Falls through after the timeout so a page that never settles (e.g. an
   * animation) still gets captured.
   */
  private function waitForStableRender(int $timeout = 10000): void {
    $session = $this->getSession();
    // Seed the height tracker and require two painted frames, so the
    // condition cannot pass before the browser has painted the result of
    // the scroll/resize that triggered this wait.
    $session->executeScript(
      "window.__behatStableHeight = document.body.scrollHeight;" .
      "window.__behatFramesPainted = false;" .
      "requestAnimationFrame(function () { requestAnimationFrame(function () { window.__behatFramesPainted = true; }); });"
    );
    $session->wait($timeout, self::STABLE_RENDER_CONDITION);
  }
}


