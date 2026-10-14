<?php

namespace digitalistse\BehatTools\Context;

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\ExpectationException;
use Behat\MinkExtension\Context\RawMinkContext;

/**
 * Steps that drive CKEditor 5 on Drupal text fields through a real browser.
 *
 * Fields are addressed by the locator Drupal puts in the form item wrapper,
 * so "body" means the first delta of the body field
 * (div.js-form-item-body-0-value). Toolbar buttons are addressed by their
 * English label and matched against the editor's own translation, so the
 * steps work whatever language the admin UI runs in.
 *
 * Needs a JavaScript session (@javascript). Balloon forms (link, image URL)
 * are submitted with Enter or a scripted click on purpose: a WebDriver click
 * scrolls the page to the top, the balloon target leaves the viewport and
 * CKEditor parks the balloon off screen.
 */
class CKEditor5Context extends RawMinkContext {

  /**
   * @Then the wysiwyg on field :locator should use the :format format
   */
  public function theWysiwygOnFieldShouldUseTheFormat($locator, $format) {
    $selector = "div.js-form-item-$locator-0-value textarea";
    $active = $this->getSession()->evaluateScript(
      "return (function () { var t = document.querySelector(" . json_encode($selector) . "); return t ? t.getAttribute('data-editor-active-text-format') : null; })();"
    );
    if ($active !== $format) {
      throw new ExpectationException(sprintf('Field "%s" uses the "%s" text format, expected "%s".', $locator, $active, $format), $this->getSession()->getDriver());
    }
  }

  /**
   * Checks the toolbar against the items the text format configures.
   *
   * CKEditor only logs a console warning (toolbarview-item-unavailable) and
   * skips an item whose plugin failed to register, so this compares the
   * configured items, the registered components and the rendered buttons.
   * Separators ("|" and "-") are left out of the list.
   *
   * @Then the wysiwyg toolbar on field :locator should have the items :items
   */
  public function theWysiwygToolbarOnFieldShouldHaveTheItems($locator, $items) {
    $expected = array_values(array_filter(array_map('trim', explode(',', $items))));
    $data = $this->evaluateJson($locator, "
      var toolbar = editor.config.get('toolbar');
      var configured = (Array.isArray(toolbar) ? toolbar : (toolbar && toolbar.items) || []).filter(function (i) { return i !== '|' && i !== '-'; });
      var missing = configured.filter(function (name) { return !editor.ui.componentFactory.has(name); });
      var rendered = Array.from(editor.ui.view.toolbar.items).filter(function (v) {
        return v.element && !v.element.classList.contains('ck-toolbar__separator') && !v.element.classList.contains('ck-toolbar__line-break');
      });
      var labels = rendered.map(function (v) {
        var el = (v.buttonView && v.buttonView.element) || v.element;
        return (v.buttonView && v.buttonView.label) || v.label || el.getAttribute('data-cke-tooltip-text') || el.className;
      });
      return {configured: configured, missing: missing, renderedCount: rendered.length, labels: labels};
    ");
    $details = sprintf('configured: [%s], missing components: [%s], rendered: %d (%s)', implode(', ', $data['configured']), implode(', ', $data['missing']), $data['renderedCount'], implode(' | ', $data['labels']));
    if ($data['configured'] !== $expected) {
      throw new ExpectationException("The toolbar configuration of field \"$locator\" differs from the expected list. $details", $this->getSession()->getDriver());
    }
    if (!empty($data['missing'])) {
      throw new ExpectationException("Toolbar items without a registered CKEditor 5 component on field \"$locator\". $details", $this->getSession()->getDriver());
    }
    if ($data['renderedCount'] !== count($expected)) {
      throw new ExpectationException("The toolbar of field \"$locator\" rendered a different number of items than configured. $details", $this->getSession()->getDriver());
    }
  }

  /**
   * @When I select all text in the wysiwyg on field :locator
   */
  public function iSelectAllTextInTheWysiwygOnField($locator) {
    $this->getSession()->executeScript("(function () {
      var editor = " . $this->editorInstanceJs($locator) . ";
      editor.editing.view.focus();
      editor.model.change(function (writer) {
        writer.setSelection(writer.createRangeIn(editor.model.document.getRoot()));
      });
    })();");
  }

  /**
   * Clicks a toolbar button by its English label.
   *
   * The label is translated with the editor's t() and Drupal.t(), so "Bold"
   * finds "Fet" on a Swedish UI. Dropdowns (heading, style) show the current
   * value as their label, so they are matched on their button's tooltip.
   *
   * @When I press the :label button in the wysiwyg toolbar on field :locator
   */
  public function iPressTheButtonInTheWysiwygToolbarOnField($label, $locator) {
    $data = $this->evaluateJson($locator, "
      var wanted = " . json_encode($label) . ";
      var candidates = [wanted, editor.t(wanted)];
      if (window.Drupal && Drupal.t) { candidates.push(Drupal.t(wanted)); }
      var views = Array.from(editor.ui.view.toolbar.items);
      var match = views.find(function (v) {
        var l = (v.buttonView && v.buttonView.label) || v.label;
        var el = (v.buttonView && v.buttonView.element) || v.element;
        var tip = el && el.getAttribute('data-cke-tooltip-text');
        return (l && candidates.indexOf(l) !== -1) || (tip && candidates.some(function (c) { return tip.indexOf(c) === 0; }));
      });
      if (!match) {
        return {error: 'not found', labels: views.map(function (v) { return (v.buttonView && v.buttonView.label) || v.label || '(separator)'; })};
      }
      document.querySelectorAll('[data-behat-click]').forEach(function (e) { e.removeAttribute('data-behat-click'); });
      var target = match.buttonView ? match.buttonView.element : match.element;
      target.setAttribute('data-behat-click', '1');
      // Keep the editor mid-viewport so balloons (link, image URL) open on screen.
      target.closest('.ck-editor').scrollIntoView({block: 'center'});
      return {ok: true};
    ");
    if (empty($data['ok'])) {
      throw new ExpectationException(sprintf('No "%s" button in the toolbar of field "%s". Buttons: %s', $label, $locator, implode(' | ', $data['labels'])), $this->getSession()->getDriver());
    }
    $button = $this->getSession()->getPage()->find('css', '[data-behat-click="1"]');
    if (!$button) {
      throw new ExpectationException(sprintf('The "%s" toolbar button of field "%s" is not clickable.', $label, $locator), $this->getSession()->getDriver());
    }
    $button->click();
  }

  /**
   * Checks editor.getData() for a substring, retrying for five seconds.
   *
   * Single quotes around the value so HTML with attributes can be asserted.
   *
   * @Then /^the wysiwyg on field "([^"]*)" should contain '([^']*)'$/
   */
  public function theWysiwygOnFieldShouldContain($locator, $html) {
    $data = '';
    for ($i = 0; $i < 20; $i++) {
      $data = (string) $this->getSession()->evaluateScript("return " . $this->editorInstanceJs($locator) . ".getData();");
      if (strpos($data, $html) !== FALSE) {
        return;
      }
      usleep(250000);
    }
    throw new ExpectationException(sprintf('The wysiwyg data of field "%s" does not contain %s. Data: %s', $locator, $html, $data), $this->getSession()->getDriver());
  }

  /**
   * Fills the URL input of the open link balloon and submits it.
   *
   * Press the "Link" toolbar button first. The form has a "Displayed text"
   * input before the URL input on CKEditor 5 44 and later; older versions
   * have the URL input alone.
   *
   * @When I submit the wysiwyg link form with :url
   */
  public function iSubmitTheWysiwygLinkFormWith($url) {
    $page = $this->getSession()->getPage();
    $input = $page->waitFor(5, function ($page) {
      $inputs = array_filter($page->findAll('css', '.ck-body-wrapper .ck-link-form input'), function ($input) {
        return $input->isVisible();
      });
      foreach ($inputs as $candidate) {
        if ($candidate->getAttribute('inputmode') === 'url') {
          return $candidate;
        }
      }
      return $inputs ? end($inputs) : NULL;
    });
    if (!$input) {
      throw new ExpectationException('The link form did not open.', $this->getSession()->getDriver());
    }
    $this->typeIntoElement($input, $url);
    $this->submitBalloonForm($input, '.ck-body-wrapper .ck-link-form input', '.ck-body-wrapper .ck-link-form button[type="submit"]', 'link form');
  }

  /**
   * Inserts an image by URL through the toolbar.
   *
   * Covers text formats with image upload disabled, where the insert image
   * button opens the URL form directly. With upload enabled the button is a
   * dropdown and this step needs extending.
   *
   * @When I insert the image :url through the wysiwyg toolbar on field :locator
   */
  public function iInsertTheImageThroughTheWysiwygToolbarOnField($url, $locator) {
    $this->iPressTheButtonInTheWysiwygToolbarOnField('Insert image', $locator);
    $page = $this->getSession()->getPage();
    $input = $page->waitFor(5, function ($page) {
      $input = $page->find('css', '.ck-body-wrapper .ck-image-insert-url input');
      return ($input && $input->isVisible()) ? $input : NULL;
    });
    if (!$input) {
      throw new ExpectationException('The insert image by URL form did not open.', $this->getSession()->getDriver());
    }
    $this->typeIntoElement($input, $url);
    $this->submitBalloonForm($input, '.ck-body-wrapper .ck-image-insert-url input', '.ck-body-wrapper .ck-image-insert-url .ck-button-save', 'insert image form');
  }

  /**
   * Waits for the editor instance, which attaches after the field's AJAX load.
   *
   * @Then the wysiwyg on field :locator should be ready
   */
  public function theWysiwygOnFieldShouldBeReady($locator) {
    $selector = "div.js-form-item-$locator-0-value .ck-editor__editable";
    $ready = $this->getSession()->wait(10000, "(function () { var el = document.querySelector(" . json_encode($selector) . "); return !!(el && el.ckeditorInstance && el.ckeditorInstance.state === 'ready'); })()");
    if (!$ready) {
      throw new ExpectationException(sprintf('No CKEditor 5 instance appeared on field "%s" within 10 seconds.', $locator), $this->getSession()->getDriver());
    }
  }

  /**
   * Picks an entry of the heading dropdown, e.g. "Heading 2" or "Paragraph".
   *
   * @When I choose :heading from the heading dropdown in the wysiwyg toolbar on field :locator
   */
  public function iChooseFromTheHeadingDropdownInTheWysiwygToolbarOnField($heading, $locator) {
    $this->iPressTheButtonInTheWysiwygToolbarOnField('Heading', $locator);
    $page = $this->getSession()->getPage();
    $classHint = 'ck-heading_' . strtolower(str_replace(' ', '', $heading));
    $item = $page->waitFor(5, function ($page) use ($heading, $classHint) {
      foreach ($page->findAll('css', '.ck-dropdown__panel-visible .ck-list__item .ck-button') as $button) {
        if ($button->isVisible() && (trim($button->getText()) === $heading || $button->hasClass($classHint))) {
          return $button;
        }
      }
      return NULL;
    });
    if (!$item) {
      $labels = array_map(function (NodeElement $b) { return trim($b->getText()); }, $page->findAll('css', '.ck-dropdown__panel-visible .ck-list__item .ck-button'));
      throw new ExpectationException(sprintf('No "%s" entry in the heading dropdown of field "%s". Entries: %s', $heading, $locator, implode(' | ', $labels)), $this->getSession()->getDriver());
    }
    $item->click();
  }

  /**
   * Appends an empty paragraph after the last block and puts the cursor
   * there, so an insert lands after the content instead of inside it.
   *
   * @When I move the cursor to the end of the wysiwyg on field :locator
   */
  public function iMoveTheCursorToTheEndOfTheWysiwygOnField($locator) {
    $this->getSession()->executeScript("(function () {
      var editor = " . $this->editorInstanceJs($locator) . ";
      editor.editing.view.focus();
      editor.model.change(function (writer) {
        var paragraph = writer.createElement('paragraph');
        writer.append(paragraph, editor.model.document.getRoot());
        writer.setSelection(paragraph, 'in');
      });
    })();");
  }

  /**
   * Closes balloons, collapses the selection and blurs the editor, so a
   * screenshot shows no transient UI.
   *
   * @When I blur the wysiwyg on field :locator
   */
  public function iBlurTheWysiwygOnField($locator) {
    $this->getSession()->executeScript("(function () {
      var editor = " . $this->editorInstanceJs($locator) . ";
      var balloon = editor.plugins.get('ContextualBalloon');
      while (balloon.visibleView) {
        balloon.remove(balloon.visibleView);
      }
      editor.model.change(function (writer) {
        writer.setSelection(writer.createPositionAt(editor.model.document.getRoot(), 'end'));
      });
      var editable = editor.ui.getEditableElement();
      if (editable && document.activeElement === editable) {
        editable.blur();
      }
    })();");
  }

  /**
   * Changes the text format select of a field and waits for the new editor.
   *
   * Drupal asks for confirmation when the field has content, and tags the
   * textarea with the new format before the old editor is destroyed, so the
   * wait looks for an instance other than the one flagged beforehand.
   *
   * @When I switch the text format of field :locator to :format
   */
  public function iSwitchTheTextFormatOfFieldTo($locator, $format) {
    $page = $this->getSession()->getPage();
    $select = $page->find('css', "div.js-form-item-$locator-0-format select");
    if (!$select) {
      throw new ExpectationException(sprintf('No text format select for field "%s".', $locator), $this->getSession()->getDriver());
    }
    $editable = json_encode("div.js-form-item-$locator-0-value .ck-editor__editable");
    $textarea = json_encode("div.js-form-item-$locator-0-value textarea");
    $this->getSession()->executeScript("(function () { var el = document.querySelector($editable); if (el && el.ckeditorInstance) { el.ckeditorInstance.behatPrevious = true; } })();");
    $select->selectOption($format);

    $switched = "(function () {
      var t = document.querySelector($textarea);
      var el = document.querySelector($editable);
      return !!(t && t.getAttribute('data-editor-active-text-format') === " . json_encode($format) . " && el && el.ckeditorInstance && !el.ckeditorInstance.behatPrevious);
    })()";
    $deadline = microtime(TRUE) + 10;
    while (microtime(TRUE) < $deadline) {
      if ($this->getSession()->evaluateScript("return $switched;")) {
        return;
      }
      $continue = $page->find('css', '.editor-change-text-format-modal .ui-dialog-buttonpane button.button--primary');
      if ($continue && $continue->isVisible()) {
        $continue->click();
      }
      usleep(200000);
    }
    $active = $this->getSession()->evaluateScript("return (function () { var t = document.querySelector($textarea); return t ? t.getAttribute('data-editor-active-text-format') : null; })();");
    throw new ExpectationException(sprintf('The editor of field "%s" did not re-attach with the "%s" format within 10 seconds (active format: %s).', $locator, $format, var_export($active, TRUE)), $this->getSession()->getDriver());
  }

  /**
   * Uploads an image through the toolbar, for formats with image upload
   * enabled: Drupal's image button is then a file dialog with a hidden
   * input. The path is relative to the Mink files_path.
   *
   * @When I upload the image :file through the wysiwyg toolbar on field :locator
   */
  public function iUploadTheImageThroughTheWysiwygToolbarOnField($file, $locator) {
    $path = rtrim((string) $this->getMinkParameter('files_path'), '/') . '/' . $file;
    if (!is_file($path)) {
      throw new ExpectationException("The image file $path does not exist.", $this->getSession()->getDriver());
    }
    $input = $this->getSession()->getPage()->find('css', "div.js-form-item-$locator-0-value .ck-toolbar input[type=\"file\"]");
    if (!$input) {
      throw new ExpectationException(sprintf('No file input in the toolbar of field "%s". Does the text format allow image upload?', $locator), $this->getSession()->getDriver());
    }
    $before = (int) $this->getSession()->evaluateScript('return ' . $this->uploadedImageCountJs($locator) . ';');
    $input->attachFile($path);

    // Done when one more image has a real src and the placeholder is gone.
    $uploaded = $this->getSession()->wait(20000, '(' . $this->uploadedImageCountJs($locator) . ' > ' . $before . ') && !document.querySelector(' . json_encode("div.js-form-item-$locator-0-value .ck-upload-placeholder-loader") . ')');
    if (!$uploaded) {
      $data = (string) $this->getSession()->evaluateScript("return " . $this->editorInstanceJs($locator) . ".getData();");
      throw new ExpectationException(sprintf('The image upload on field "%s" did not finish within 20 seconds. Data: %s', $locator, $data), $this->getSession()->getDriver());
    }
  }

  /**
   * Checks the source editing textarea, after pressing the "Source" button.
   *
   * @Then /^the wysiwyg source editing area on field "([^"]*)" should contain '([^']*)'$/
   */
  public function theWysiwygSourceEditingAreaOnFieldShouldContain($locator, $text) {
    $page = $this->getSession()->getPage();
    $textarea = $page->waitFor(5, function ($page) use ($locator) {
      $t = $page->find('css', "div.js-form-item-$locator-0-value .ck-source-editing-area textarea");
      return ($t && $t->isVisible()) ? $t : NULL;
    });
    if (!$textarea) {
      throw new ExpectationException(sprintf('Source editing is not active on field "%s".', $locator), $this->getSession()->getDriver());
    }
    $value = $textarea->getValue();
    if (strpos($value, $text) === FALSE) {
      throw new ExpectationException(sprintf('The source editing area of field "%s" does not contain %s. Source: %s', $locator, $text, $value), $this->getSession()->getDriver());
    }
  }

  /**
   * JavaScript expression counting images whose src is not a data URI.
   */
  protected function uploadedImageCountJs($locator) {
    return '(' . $this->editorInstanceJs($locator) . '.getData().match(/<img\b[^>]*\bsrc="(?!data:)/g) || []).length';
  }

  /**
   * JavaScript expression resolving the CKEditor 5 instance of a field.
   */
  protected function editorInstanceJs($locator) {
    $selector = "div.js-form-item-$locator-0-value .ck-editor__editable";
    return "(function () {
      var el = document.querySelector(" . json_encode($selector) . ");
      if (!el || !el.ckeditorInstance) {
        throw new Error('No CKEditor 5 instance found on field $locator');
      }
      return el.ckeditorInstance;
    })()";
  }

  /**
   * Runs a script with the editor in scope and returns its decoded object.
   *
   * The Selenium driver prepends "return " to evaluated scripts, so the body
   * is wrapped in a function expression.
   */
  protected function evaluateJson($locator, $body) {
    $result = $this->getSession()->evaluateScript("return (function () {
      var editor = " . $this->editorInstanceJs($locator) . ";
      return JSON.stringify((function () { $body })());
    })();");
    $data = json_decode((string) $result, TRUE);
    if (!is_array($data)) {
      throw new ExpectationException("The CKEditor 5 script for field \"$locator\" returned nothing: " . var_export($result, TRUE), $this->getSession()->getDriver());
    }
    return $data;
  }

  /**
   * Types with real keystrokes and without the blur that Mink's setValue()
   * adds, which closes CKEditor balloons.
   */
  protected function typeIntoElement(NodeElement $element, $text) {
    $driver = $this->getSession()->getDriver();
    if (method_exists($driver, 'getWebDriverSession')) {
      // W3C WebDriver (Selenium 4) takes the keys as 'text'.
      $driver->getWebDriverSession()->element('xpath', $element->getXpath())->postValue(['text' => $text]);
      return;
    }
    $element->setValue($text);
  }

  /**
   * Submits an open balloon form: Enter first, then a scripted click.
   */
  protected function submitBalloonForm(NodeElement $input, $inputSelector, $buttonSelector, $what) {
    $before = $this->balloonStateSnapshot("before submitting the $what");
    $isOpen = "(function () { var i = document.querySelector(" . json_encode($inputSelector) . "); return !!(i && i.offsetParent !== null); })()";
    $this->typeIntoElement($input, "\u{E007}");
    if ($this->getSession()->wait(2000, "!" . $isOpen)) {
      return;
    }
    $this->getSession()->executeScript("(function () {
      var b = Array.from(document.querySelectorAll(" . json_encode($buttonSelector) . ")).find(function (e) { return e.offsetParent !== null; });
      if (b) { b.click(); }
    })();");
    if ($this->getSession()->wait(2000, "!" . $isOpen)) {
      return;
    }
    throw new ExpectationException("The $what did not close after submitting it. $before " . $this->balloonStateSnapshot('after submitting'), $this->getSession()->getDriver());
  }

  /**
   * Scroll, focus and balloon state, for failure messages.
   */
  protected function balloonStateSnapshot($label) {
    return $label . ': ' . $this->getSession()->evaluateScript("return (function () {
      var a = document.activeElement;
      var balloons = Array.from(document.querySelectorAll('.ck-balloon-panel')).map(function (b) {
        var r = b.getBoundingClientRect();
        return {rect: [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)], visibleClass: b.classList.contains('ck-balloon-panel_visible'), text: (b.innerText || '').replace(/\\s+/g, ' ').slice(0, 200), inputs: Array.from(b.querySelectorAll('input')).map(function (i) { return i.value; })};
      });
      return JSON.stringify({scroll: [window.scrollX, window.scrollY], viewport: [window.innerWidth, window.innerHeight], active: a ? (a.tagName + '.' + a.className).slice(0, 80) : null, balloons: balloons});
    })();");
  }

}
