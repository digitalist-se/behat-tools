<?php

namespace digitalistse\BehatTools\Context;

use Behat\Behat\Definition\Call\Then;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;
use Drupal\DrupalExtension\Context\RawDrupalContext;

class CommonContext extends RawDrupalContext {

  /**
   * @Given I click the :arg1 element
   */
  public function iClickTheElement($selector) {
    $page = $this->getSession()->getPage();
    $element = $page->find('css', $selector);

    if (empty($element)) {
      throw new Exception("No html element found for the selector ('$selector')");
    }

    $element->click();
  }

  /**
   * @Given I click the text :arg1
   */
  public function iClickTheText($arg1) {
    $page = $this->getSession()->getPage();
    $element = $page->find('xpath', "//*[text()='$arg1']");
    if (empty($element)) {
      throw new Exception("No html element found for the text ('$arg1')");
    }

    $element->click();
  }

  /**
   * @Given for :field I enter :value inside the element :wrapper
   * @Given I enter :value for :field inside the element :wrapper
   */
  public function assertEnterFieldInsideElement($field, $value, $selector) {
    $element = $this->getSession()->getPage()->find('css', $selector);

    if (empty($element)) {
      throw new Exception("No html element found for the text ('$selector')");
    }
    $element->fillField($field, $value);

  }

  /**
   *
   * @throws \Exception
   */
  public function executeJavascript($function) {
    try {
      $this->getSession()->executeScript($function);
    }
    catch (Exception $e) {
      throw new \Exception("Javascript '$function'' failed");
    }
  }

  /**
   * @When I scroll the top of :elementIdOr into view
   */
  public function scrollTopIntoView($elementId) {
    $this->scrollintoViewHelper($elementId, TRUE);
  }

  /**
   * @When I scroll :elementIdOr into view
   */
  public function scrollIntoView($elementId) {
    $this->scrollintoViewHelper($elementId, FALSE);
  }

  protected function scrollintoViewHelper($elementId, $align_top) {
    if (strpos($elementId, '#') === 0) {
      $elementId = substr($elementId, 1);
      $function = <<<JS
(function(){
  var elem = document.getElementById("$elementId");
  elem.scrollIntoView($align_top);
})()
JS;
    }
    elseif (strpos($elementId, '.') === 0) {
      $class = substr($elementId, 1);
      $function = <<<JS
(function(){
  var elem = document.getElementsByClassName("$class")[0];
  elem[0].scrollIntoView($align_top);
})()
JS;
    }
    $this->executeJavascript($function);
  }

  /**
   * @Then /^I scroll the selector "([^"]*)" into view$/
   */
  public function iScrollTheSelectorIntoView($selector) {
    $function = <<<JS
(function(){
  var elem = document.querySelector("$selector");
  elem.scrollIntoView({block: 'center'});
})()
JS;
    $this->executeJavascript($function);
  }


  /**
   * @When I scroll to the top
   */
  public function scrollToTop() {
    $function = <<<JS
(function(){
  window.scrollTo(0, 0);
})()
JS;
    $this->executeJavascript($function);
  }

  /**
   * @When I clear the Javascript localStorage
   */
  public function clearLocalStorage() {
    $function = <<<JS
(function(){
  localStorage.clear();
})()
JS;
    $this->executeJavascript($function);
  }

  protected function fixStepArgument($argument) {
    return str_replace('\\"', '"', $argument);
  }

  /**
   * Checks the form fields against provided table
   * Example: When I fill in the following"
   *              | username | bruceWayne |
   *              | password | iLoveBats123 |
   * Example: And I fill in the following"
   *              | username | bruceWayne |
   *              | password | iLoveBats123 |
   *
   * @Then /^the fields should contain the following:$/
   */
  public function checkFields(TableNode $fields) {
    foreach ($fields->getRowsHash() as $field => $value) {
      $field = $this->fixStepArgument($field);
      $value = $this->fixStepArgument($value);
      $this->assertSession()->fieldValueEquals($field, $value);
    }
  }

  /**
   * @Given /^(?:|I )wait(?:| for) (\d+) seconds?$/
   *
   * Wait for the given number of seconds. ONLY USE FOR DEBUGGING!
   */
  public function iWaitForSeconds($arg1) {
    sleep($arg1);
  }

  /**
   * Wait for AJAX to finish.
   *
   * @see \Drupal\FunctionalJavascriptTests\JSWebAssert::assertWaitOnAjaxRequest()
   *
   * @Then I wait for AJAX at least :arg1 seconds
   */
  public function iWaitForAjaxAtLeastSeconds($seconds) {
    $condition = <<<JS
    (function() {
      function isAjaxing(instance) {
        return instance && instance.ajaxing === true;
      }
      return (
        // Assert no AJAX request is running (via jQuery or Drupal) and no
        // animation is running.
        (typeof jQuery === 'undefined' || (jQuery.active === 0 && jQuery(':animated').length === 0)) &&
        (typeof Drupal === 'undefined' || typeof Drupal.ajax === 'undefined' || !Drupal.ajax.instances.some(isAjaxing))
      );
    }());
JS;
    $result = $this->getSession()->wait(($seconds * 1000), $condition);
    if (!$result) {
      throw new \RuntimeException('Unable to complete AJAX request.');
    }
  }

  protected function storage($name, $value = NULL) {
    static $_storage;
    if (isset($value)) {
      $_storage[$name] = $value;
    }
    return $_storage[$name];
  }

  /**
   * @BeforeScenario @honeypot
   * @param \Behat\Behat\Hook\Scope\BeforeScenarioScope $scope
   */
  public function beforeHoneyPotScenario(BeforeScenarioScope $scope) {
    // If we have honeypot installed then ensure that we disable time_limit
    // So that automated tests / bots can run
    $config = \Drupal::configFactory()->getEditable('honeypot.settings');
    $honeypot_time_limit = $config->get('time_limit');
    $this->storage('honeypot_time_limit', $honeypot_time_limit);
    if ($honeypot_time_limit) {
      $config
        ->set('time_limit', '0')
        ->save();
    }
  }

  /**
   * @AfterScenario @honeypot
   * @param \Behat\Behat\Hook\Scope\AfterScenarioScope $scope
   */
  public function afterHoneyPotScenario(AfterScenarioScope $scope) {
    $config = \Drupal::configFactory()->getEditable('honeypot.settings');
    // Ensure we protect against spambots again if honeypot is installed
    if ($this->storage('honeypot_time_limit')) {
      $config
        ->set('time_limit', $this->storage('honeypot_time_limit'))
        ->save();
    }
  }

  /**
   * @Then /^I wait for the page to load$/
   */
  public function iWaitForThePageToLoad() {
    $this->getSession()->wait(10000, "document.readyState === 'complete'");
  }


  /**
   * @BeforeScenario @eu_cookie_compliance
   * @param \Behat\Behat\Hook\Scope\BeforeScenarioScope $scope
   */
  public function beforeEuCookieComplianceScenario(BeforeScenarioScope $scope) {
    \Drupal::service('module_installer')->uninstall(['eu_cookie_compliance']);
  }

  /**
   * @AfterScenario @eu_cookie_compliance
   * @param \Behat\Behat\Hook\Scope\AfterScenarioScope $scope
   */
  public function afterEuCookieComplianceScenario(AfterScenarioScope $scope) {
    \Drupal::service('module_installer')->install(['eu_cookie_compliance']);
  }

  /**
   * @BeforeScenario @javascript
   * @param \Behat\Behat\Hook\Scope\BeforeScenarioScope $scope
   */
  public function beforeMaximizeScenario(BeforeScenarioScope $scope) {
    $this->getSession()->resizeWindow(1980, 1080, 'current');
  }

  /**
   * @Then I fill in wysiwyg on field :locator with :value
   */
  public function iFillInWysiwygOnFieldWith($locator, $value) {
    $el = $this->getSession()->getPage()->findField($locator);

    if (empty($el)) {
      throw new ExpectationException('Could not find WYSIWYG with locator: ' . $locator, $this->getSession());
    }

    $fieldId = $el->getAttribute('id');

    if (empty($fieldId)) {
      throw new Exception('Could not find an id for field with locator: ' . $locator);
    }

    $this->getSession()
      ->executeScript("CKEDITOR.instances[\"$fieldId\"].setData(\"$value\");");
  }


  /**
   * @When /^I switch to the "([^"]*)" IFrame$/
   */
  public function iSwitchToTheIframe($locator) {
    $this->getSession()
      ->getDriver()
      ->switchToIFrame(empty($locator) ? NULL : $locator);
  }

  /**
   * @Then /^(?:|I )upload the file "(?P<path>[^"]*)" to the dropzone widget/
   *
   * Requires jQuery
   */
  public function iUploadDropzoneImage($path) {
    $prepare_fake_input_script = <<<JS
    (function() {
      var fakeFileInput = window.jQuery('<input/>').attr(
        {id: 'fakeFileInput', type:'file'}
      ).appendTo('body');
      console.log(fakeFileInput);
    }());
JS;
    $this->getSession()->evaluateScript($prepare_fake_input_script);
    $field = 'fakeFileInput';
    $field = $this->fixStepArgument($field);
    $path = rtrim(realpath($this->getMinkParameter('files_path')), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    $this->getSession()->getPage()->attachFileToField($field, $path);
    $drop_file_script = <<<JS
    (function() {
      var fakeFileInput = jQuery('#fakeFileInput');
      var e = jQuery.Event('drop', { dataTransfer : { files : [fakeFileInput.get(0).files[0]] } });
      jQuery('.dropzone')[0].dropzone.listeners[0].events.drop(e);
    }());
JS;
    $this->getSession()->evaluateScript($drop_file_script);
    // Wait for the IEF to appear
    $page = $this->getSession()->getPage();
    $page->waitFor(5000,
      function () use ($page) {
        sleep(1);
        $element = $page->findById('ief-dropzone-upload');
        return $element && $element->isVisible();
      }
    );
  }

  /**
   * Click on the element with the provided CSS Selector
   *
   * @When /^I click on the element with css selector "([^"]*)"$/
   */
  public function iClickOnTheElementWithCSSSelector($cssSelector) {
    $session = $this->getSession();
    $element = $session->getPage()->find(
      'xpath',
      $session->getSelectorsHandler()
        ->selectorToXpath('css', $cssSelector) // just changed xpath to css
    );
    if (NULL === $element) {
      throw new \InvalidArgumentException(sprintf('Could not evaluate CSS Selector: "%s"', $cssSelector));
    }

    $element->click();

  }

  /**
   * Fills in specified field with date.
   *
   * Example: When I fill in "field_ID" with date "now"
   * Example: When I fill in "field_ID" with date "-7 days"
   * Example: When I fill in "field_ID" with date "+7 days"
   * Example: When I fill in "field_ID" with date "-/+0 weeks"
   * Example: When I fill in "field_ID" with date "-/+0 years"
   *
   * @Then I fill in :arg1 with date :arg2
   */
  public function iFillInWithDate($field, $value) {
    $newDate = strtotime((string) $value);
    $dateToSet = date("m/d/Y", $newDate);
    $this->getSession()->getPage()->fillField($field, $dateToSet);
  }

  /**
   * @Given /^I set the page with the title "([^"]*)" as frontpage$/
   *
   * @param string $name
   *   Title of the node to set as frontpage
   */
  public function setLastNodeAsFrontpage($node_title) {
    $node = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => $node_title]);
    $nid = reset($node)->id();
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('system.site')
      ->set('page.front', '/node/' . $nid)
      ->save(TRUE);
  }

  /**
   * Click on the element with the provided xpath query
   *
   * @Given /^I click on the element with xpath "([^"]*)"$/
   */
  public function iClickOnTheElementWithXPath($xpath)
  {
    $session = $this->getSession(); // get the mink session
    $element = $session->getPage()->find(
      'xpath',
      $session->getSelectorsHandler()->selectorToXpath('xpath', $xpath)
    ); // runs the actual query and returns the element

    // errors must not pass silently
    if (null === $element) {
      throw new \InvalidArgumentException(sprintf('Could not evaluate XPath: "%s"', $xpath));
    }

    // ok, let's click on it
    $element->click();

  }
  
  /**
   * @Then /^the "(?P<select>(?:[^"]|\\")*)" select should contain "(?P<option>(?:[^"]|\\")*)"$/
   */
  public function theSelectShouldContain($select, $option) {
    $selectElement = $this->getSession()->getPage()->findField($select);

    if (!$selectElement) {
      throw new \Exception(sprintf('The select "%s" was not found on the page', $select));
    }

    $options = $selectElement->findAll('css', 'option');
    $optionValues = array_map(function($option) {
      return $option->getText();
    }, $options);

    if (!in_array($option, $optionValues)) {
      throw new \Exception(sprintf('The select "%s" does not contain the option "%s"', $select, $option));
    }
  }

  /**
   * @Then /^the "(?P<select>(?:[^"]|\\")*)" select should not contain "(?P<option>(?:[^"]|\\")*)"$/
   */
  public function theSelectShouldNotContain($select, $option) {
    $selectElement = $this->getSession()->getPage()->findField($select);

    if (!$selectElement) {
      throw new \Exception(sprintf('The select "%s" was not found on the page', $select));
    }

    $options = $selectElement->findAll('css', 'option');
    $optionValues = array_map(function($option) {
      return $option->getText();
    }, $options);

    if (in_array($option, $optionValues)) {
      throw new \Exception(sprintf('The select "%s" contains the option "%s", but it should not', $select, $option));
    }
  }
}
