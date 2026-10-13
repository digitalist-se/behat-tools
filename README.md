# behat-tools
Behat related tools

## Set up

Add this to the `repositories` section of your `composer.json`

```json
        {
            "type": "vcs",
            "url": "https://github.com/digitalist-se/behat-tools"
        },
```

Execute:
```sh
composer require --dev digitalist-se/behat-tools
```

Add to your `behat.yml`:
```yml
default:
  suites:
    default:
      contexts:
        - digitalistse\BehatTools\Context\CommonContext
```
Add more Contexts depending on your needs using the same structure.
# Entity Context

You can set the format of the dates for each field in the behat.yml like this:

```yml
default:
  suites:
    default:
      parameters:
        entity_context:
          datetime_format:
            announcement:
              publish_date: 'Y-m-d'
              unpublish_date: 'Y-m-d'
            announcement_tracker:
              created_date: 'Y-m-d\TH:i:s'
              sent_date: 'Y-m-d\TH:i:s'
              read_date: 'Y-m-d\TH:i:s'
```
In that case you could use relative date in php format like:
```gherkin
Given a "license_tracker" entity exists with the properties:
      | label | status | uid:user:mail       | field_license:license:title | created     | activated   | expire  | first_notification | second_notification | service_requirement_expired |
      | TRK10 | 1      | license-01@test.com | Test license 1              | 6 month ago | 6 month ago | 91 days | tomorrow           | 61 days             | tomorrow                    |
      | TRK10 | 1      | license-02@test.com | Test license 1              | 6 month ago | 6 month ago | 90 days | today              | 60 days             | tomorrow                    |
      | TRK10 | 1      | license-03@test.com | Test license 1              | 6 month ago | 6 month ago | 89 days | yesterday          | 59 days             | tomorrow                    |
```

**Entity fields support**  

Entity fields are supported like in the following example: `(<field_type>) <field_name>.<field_property>`

- Field with simple structure (e.g.: boolean, textfield, etc.): `field_archived.value`
- Field type with more complex structure: `(daterange) field_date.value`

> NOTE: Only `daterange` type is supported at the moment. If you need to support more complex fields you can
> add processing to `EntityContext::processEntityFields`.

Example using entity fields and properties
```gherkin
Given a "license_tracker" entity exists with the properties:
      | label | status | uid:user:mail       | field_archived.value | (daterange) field_date.value | (daterange) field_date.end_value |
      | TRK10 | 1      | license-01@test.com | 1                    | 6 month ago                  | 6 month ago                      |
      | TRK10 | 1      | license-02@test.com | 0                    | 6 month ago                  | 6 month ago                      |
      | TRK10 | 1      | license-03@test.com | 1                    | 6 month ago                  | 6 month ago                      |
```

# Screenshot Context

The `ScreenshotContext` needs display sizes declared in your `behat.yml` under the suite settings key `screenshot_context`. Define a desktop size and any number of named mobile devices to ensure predictable, comparable screenshots.

Recommended configuration:
```yml
default:
  suites:
    default:
      contexts:
        - digitalistse\BehatTools\Context\ScreenshotContext
      screenshot_context:
        screenshot_path: '%paths.base%/screenshots-build'   # required
        desktop_size:                                       # strongly recommended
          width: 1920
          height: 1080
        mobile_devices:                                     # optional, add any devices you want
          iphone8:
            width: 375
            height: 667
          ipad:
            width: 768
            height: 1024
        desktop_subfolder: 'desktop'                        # optional (default: desktop)
        mobile_subfolder: 'mobile'                          # optional (default: mobile)
```

Notes:
- One desktop screenshot is always taken using `desktop_size` (defaults to 1920x1080 if not set).
- One additional screenshot is taken per entry in `mobile_devices`, saved under the `mobile_subfolder` using the device name in the filename.
- If no `mobile_devices` are configured, only the desktop screenshot is produced.

Legacy configuration (backwards compatibility):
```yml
default:
  suites:
    default:
      screenshot_context:
        screenshot_path: '%paths.base%/screenshots-build'
        display_sizes:
          desktop:
            width: 1920
            height: 1080
          mobile:
            width: 375
            height: 667
```
When using `display_sizes`, the desktop size is read from `display_sizes.desktop`, and a single mobile device named `mobile` is inferred from `display_sizes.mobile`. Other keys (e.g. `tablet`) are ignored by the current implementation. Prefer the recommended `desktop_size` + `mobile_devices` format for multiple devices.

## CKEditor5Context

Drives CKEditor 5 on Drupal text fields in a real browser (`@javascript`
scenarios). Add it to `behat.yml`:

```
        - digitalistse\BehatTools\Context\CKEditor5Context
```

Fields are addressed by the locator in Drupal's form item wrapper, so `body`
means `div.js-form-item-body-0-value`; a paragraph field looks like
`field-sections-0-subform-field-text`. Toolbar buttons are addressed by their
English label and matched against the editor's own translation, so `Bold`
finds `Fet` on a Swedish UI.

| Step | What it does |
| --- | --- |
| `I fill in wysiwyg on field "body" with "<p>Text</p>"` | Sets the editor content with `setData()`, bypassing the UI. Defined in CommonContext, so it is available without this context. |
| `the wysiwyg on field "body" should use the "basic_html" format` | Asserts the active text format. |
| `the wysiwyg toolbar on field "body" should have the items "heading, bold, link"` | Compares the configured toolbar items (without separators) with the registered CKEditor components and the rendered buttons. CKEditor only console-warns when a plugin fails to load, so this is the check that catches a silently missing button. |
| `I select all text in the wysiwyg on field "body"` | Selects the whole document in the editor model. |
| `I press the "Bold" button in the wysiwyg toolbar on field "body"` | Real click on a toolbar button found by its English label. |
| `the wysiwyg on field "body" should contain '<strong>Text</strong>'` | Substring check on `editor.getData()`, retried for five seconds. Single quotes, so HTML attributes can be asserted. |
| `I submit the wysiwyg link form with "https://example.com/"` | After pressing `Link`: types the URL into the URL input (not the "displayed text" input) and submits with Enter. |
| `I insert the image "/core/misc/druplicon.png" through the wysiwyg toolbar on field "body"` | Presses `Insert image` and submits the by-URL form. Covers formats with image upload disabled; with upload enabled the button is a dropdown and the step needs extending. |
| `the wysiwyg source editing area on field "body" should contain '<strong>'` | After pressing `Source`: checks the source textarea. |

Balloon forms are submitted with Enter or a scripted click on purpose. A
WebDriver click inside a balloon scrolls the page to the top, the balloon
target leaves the viewport and CKEditor parks the balloon off screen. Typing
into balloon inputs uses raw WebDriver keystrokes because Mink's `setValue()`
blurs the field afterwards, which closes the balloon.

Example, on a site where editors use `restricted_html`:

```
  @api @javascript
  Scenario: Toolbar buttons format text and the formatting survives saving
    Given I am logged in as a user with the "editor" role
    And I am on "/node/add/page"
    Then the wysiwyg toolbar on field "body" should have the items "heading, bold, italic, link, sourceEditing"
    When I fill in wysiwyg on field "body" with "<p>Toolbar test text</p>"
    And I select all text in the wysiwyg on field "body"
    And I press the "Bold" button in the wysiwyg toolbar on field "body"
    Then the wysiwyg on field "body" should contain '<strong>Toolbar test text</strong>'
    When I press the "Link" button in the wysiwyg toolbar on field "body"
    And I submit the wysiwyg link form with "https://example.com/"
    Then the wysiwyg on field "body" should contain '<a href="https://example.com/">'
```
