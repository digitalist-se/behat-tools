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
   *
   * For <picture> elements the media attributes are directive: the
   * browser must switch images whenever a different <source> starts to
   * match, and screenshots resize the window, which re-runs that
   * selection. img.complete is not a reliable guard here — it still
   * reports true for the previously committed image while the swap to
   * the newly matching source is pending, so a capture can race the
   * swap and freeze a stale derivative (a real flake: hero images with
   * per-breakpoint crops captured mid-swap after a resize). So for
   * every in-viewport <picture> img, also require currentSrc to BE the
   * candidate of the first <source> whose media currently matches.
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
    var picture = img.parentElement && img.parentElement.tagName === 'PICTURE' ? img.parentElement : null;
    if (picture && img.currentSrc) {
      var sources = picture.querySelectorAll('source');
      var expected = null;
      for (var k = 0; k < sources.length; k++) {
        var media = sources[k].getAttribute('media');
        if (media && !window.matchMedia(media).matches) { continue; }
        expected = sources[k];
        break;
      }
      // First candidate URL of the matching <source> (or the img src
      // fallback when none matches) must be what is committed now.
      var candidate = expected ? (expected.getAttribute('srcset') || '') : (img.getAttribute('src') || '');
      candidate = candidate.split(',')[0].trim().split(/\s+/)[0];
      if (candidate) {
        var resolved = new URL(candidate, window.location.href).href;
        if (img.currentSrc !== resolved) { return false; }
      }
    }
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
   * What to do when a captured page is wider than its viewport.
   *
   * 'fail' throws after the capture (the screenshots are still written so
   * the artifact shows the broken layout), 'warn' only prints, 'off' skips
   * the check. Configured via screenshot_context.horizontal_overflow.
   *
   * @var string
   */
  private string $horizontalOverflow = 'fail';

  /**
   * TRUE while a Chrome device-metrics override is active.
   *
   * @var bool
   */
  private bool $emulationActive = FALSE;

  /**
   * Overflow findings collected during the current screenshot step.
   *
   * @var string[]
   */
  private array $overflowFindings = [];

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
    if (!empty($settings['screenshot_context']['horizontal_overflow'])) {
      $mode = strtolower((string) $settings['screenshot_context']['horizontal_overflow']);
      if (!in_array($mode, ['fail', 'warn', 'off'], TRUE)) {
        throw new \InvalidArgumentException("screenshot_context.horizontal_overflow must be 'fail', 'warn' or 'off', got '{$mode}'.");
      }
      $this->horizontalOverflow = $mode;
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

    $driver = $this->getSession()->getDriver();
    $this->overflowFindings = [];

    // Desktop screenshot.
    $this->setViewport($this->desktopSize['width'], $this->desktopSize['height'], FALSE);
    $this->waitForStableRender();
    $this->recordOverflow('desktop', $this->desktopSize['width']);
    $desktopFilename = $desktopDir . '/' . $this->currentFeature . '-' . $name . '.' . self::SCREENSHOT_EXTENSION;
    file_put_contents($desktopFilename, $driver->getScreenshot());

    // Mobile screenshots per device.
    foreach ($this->mobileDevices as $deviceName => $dimensions) {
      $this->setViewport($dimensions['width'], $dimensions['height'], TRUE);
      $this->waitForStableRender();
      $this->recordOverflow($deviceName, $dimensions['width']);
      $mobileFilename = $mobileDir . '/' . $this->currentFeature . '-' . $name . '-' . $deviceName . '.' . self::SCREENSHOT_EXTENSION;
      file_put_contents($mobileFilename, $driver->getScreenshot());
    }

    // Restore desktop size.
    $this->setViewport($this->desktopSize['width'], $this->desktopSize['height'], FALSE);
    $this->reportOverflow();
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
    $this->overflowFindings = [];

    // Helper to capture full page for a given size and target directory.
    $captureFullPage = function (int $width, int $height, bool $mobile, string $label, string $targetDir, string $filenamePrefix) use ($session, $driver) {
      \Drupal::service('file_system')->prepareDirectory($targetDir, FileSystemInterface::CREATE_DIRECTORY);
      $this->setViewport($width, $height, $mobile);

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
      $this->recordOverflow($label, $width);

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
        FALSE,
        'desktop',
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
          TRUE,
          $deviceName,
          $mobileDir,
          $this->currentFeature . '-' . $name . '-' . $deviceName
        );
      }
      $captured[] = 'mobile';
    }

    // Restore desktop size.
    $this->setViewport($this->desktopSize['width'], $this->desktopSize['height'], FALSE);

    $capturedTypes = !empty($captured) ? implode(', ', $captured) : 'none';
    echo "Screenshot saved: {$name} (captured: {$capturedTypes})\n";
    $this->reportOverflow();
  }

  /**
   * Fails when the page is wider than the current viewport.
   *
   * The screenshot steps run this check automatically for every captured
   * size (see screenshot_context.horizontal_overflow); use this step to
   * assert it at the current window size without taking a screenshot.
   *
   * @Then /^the page should not overflow horizontally$/
   */
  public function thePageShouldNotOverflowHorizontally(): void {
    $finding = $this->horizontalOverflowFinding('current viewport');
    if ($finding !== NULL) {
      throw new \RuntimeException($finding);
    }
  }

  /**
   * Switches the browser to a viewport size.
   *
   * Desktop sizes resize the window. Mobile devices use Chrome's device
   * emulation instead: a headed Chrome refuses to make its window narrower
   * than roughly 500px, so resizing silently produced 500px "mobile"
   * captures and layouts broken on real phones passed. The override is sent
   * through chromedriver's CDP endpoint; other drivers fall back to resizing.
   */
  private function setViewport(int $width, int $height, bool $mobile): void {
    $driver = $this->getSession()->getDriver();
    if (!$mobile) {
      $this->clearEmulation();
      $driver->resizeWindow($width, $height, 'current');
      return;
    }
    // The emulated viewport renders inside the real window, so keep the
    // window at least as large as the device.
    $driver->resizeWindow(max($this->desktopSize['width'], $width), max($this->desktopSize['height'], $height), 'current');
    $applied = $this->cdp('Emulation.setDeviceMetricsOverride', [
      'width' => $width,
      'height' => $height,
      'deviceScaleFactor' => 1,
      'mobile' => TRUE,
    ]);
    if ($applied) {
      $this->emulationActive = TRUE;
      return;
    }
    $driver->resizeWindow($width, $height, 'current');
  }

  /**
   * Removes the device-metrics override, if one is active.
   */
  private function clearEmulation(): void {
    if ($this->emulationActive) {
      $this->cdp('Emulation.clearDeviceMetricsOverride', []);
      $this->emulationActive = FALSE;
    }
  }

  /**
   * Sends a Chrome DevTools Protocol command through chromedriver.
   *
   * @return bool
   *   TRUE when chromedriver accepted the command, FALSE for any other
   *   driver or on error.
   */
  private function cdp(string $command, array $params): bool {
    $driver = $this->getSession()->getDriver();
    if (!method_exists($driver, 'getWebDriverSession')) {
      return FALSE;
    }
    $webDriverSession = $driver->getWebDriverSession();
    if (!is_object($webDriverSession) || !method_exists($webDriverSession, 'getURL')) {
      return FALSE;
    }
    $url = rtrim((string) $webDriverSession->getURL(), '/') . '/goog/cdp/execute';
    $payload = json_encode(['cmd' => $command, 'params' => (object) $params]);
    $handle = curl_init($url);
    curl_setopt_array($handle, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($body === FALSE || $status >= 400) {
      return FALSE;
    }
    $decoded = json_decode((string) $body, TRUE);
    // The legacy (JSON wire) protocol reports errors with a non-zero status.
    return !(is_array($decoded) && isset($decoded['status']) && (int) $decoded['status'] !== 0);
  }

  /**
   * Describes a horizontal overflow of the page, or NULL when it fits.
   *
   * Lists the outermost elements that stick out past the viewport, skipping
   * anything inside a horizontally clipping or scrolling ancestor (a wide
   * table in an overflow-x: auto wrapper is fine).
   */
  private function horizontalOverflowFinding(string $label): ?string {
    $script = <<<'JS'
return (function () {
  var doc = document.documentElement;
  var vw = doc.clientWidth;
  var sw = Math.max(doc.scrollWidth, document.body ? document.body.scrollWidth : 0);
  if (sw <= vw + 1) { return null; }
  var clipped = function (el) {
    for (var p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      var ox = getComputedStyle(p).overflowX;
      if (ox === 'hidden' || ox === 'clip' || ox === 'auto' || ox === 'scroll') { return true; }
    }
    return false;
  };
  var sticksOut = function (el) { return el.getBoundingClientRect().right > vw + 1; };
  var offenders = [];
  var all = document.body.getElementsByTagName('*');
  for (var i = 0; i < all.length; i++) {
    var el = all[i];
    var r = el.getBoundingClientRect();
    if (r.width === 0 || !sticksOut(el) || clipped(el)) { continue; }
    // Report the outermost offender only; its children stick out with it.
    if (el.parentElement && el.parentElement !== document.body && sticksOut(el.parentElement)) { continue; }
    var name = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '');
    var cls = (typeof el.className === 'string' ? el.className : '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
    if (cls.length) { name += '.' + cls.join('.'); }
    offenders.push(name + ' (right edge ' + Math.round(r.right) + 'px, width ' + Math.round(r.width) + 'px)');
  }
  return { viewport: vw, scrollWidth: sw, offenders: offenders.slice(0, 8) };
}())
JS;
    $result = $this->getSession()->evaluateScript($script);
    if (!is_array($result)) {
      return NULL;
    }
    $offenders = !empty($result['offenders']) ? implode('; ', $result['offenders']) : 'no unclipped element found';
    return sprintf(
      'Page overflows horizontally at %s: content is %dpx wide in a %dpx viewport. Sticking out: %s.',
      $label,
      (int) $result['scrollWidth'],
      (int) $result['viewport'],
      $offenders
    );
  }

  /**
   * Remembers an overflow finding for the size just captured.
   */
  private function recordOverflow(string $label, int $width): void {
    if ($this->horizontalOverflow === 'off') {
      return;
    }
    $finding = $this->horizontalOverflowFinding("{$label} ({$width}px)");
    if ($finding !== NULL) {
      $this->overflowFindings[] = $finding;
    }
  }

  /**
   * Throws or prints the findings collected during a screenshot step.
   *
   * Runs after every size was captured, so the artifact still contains the
   * screenshots of the broken layout.
   */
  private function reportOverflow(): void {
    if (!$this->overflowFindings) {
      return;
    }
    $message = implode("\n", $this->overflowFindings);
    $this->overflowFindings = [];
    if ($this->horizontalOverflow === 'fail') {
      throw new \RuntimeException($message);
    }
    echo "Warning: {$message}\n";
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


