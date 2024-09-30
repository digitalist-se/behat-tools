<?php

namespace digitalistse\BehatTools\Context;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Drupal\DrupalExtension\Context\DrupalContext;
use Drupal\DrupalExtension\Context\RawDrupalContext;
use Drupal\user\Entity\User;

class AuthDrupalContext extends RawDrupalContext {

  private $loggedInUser;

  private $drupalContext;

  private $currentUrl;

  private $newPassword;

  /** @BeforeScenario */
  public function gatherContexts(BeforeScenarioScope $scope) {
    $environment = $scope->getEnvironment();
    $this->drupalContext = $environment->getContext(DrupalContext::class);
  }

  /**
   * @Given /^I switch to a logged out session$/
   */
  public function iSwitchToALoggedOutSession() {
    $this->loggedInUser = \Drupal::currentUser()->id();
    $this->currentUrl = $this->getSession()->getCurrentUrl();
    $this->newPassword = bin2hex(random_bytes(6));

    $user = User::load($this->loggedInUser);

    if ($user) {
      $user->setPassword($this->newPassword);
      $user->save();
    }
    else {
      throw new \Exception("Current user not found.");
    }

    if (\Drupal::currentUser()->isAuthenticated()) {
      user_logout();
    }

    // Clear the browser's cookies to ensure the session is cleared
    $this->getSession()->restart();

    // Navigate to the stored URL
    $this->visitPath($this->currentUrl);
  }

  /**
   * @Given /^I switch back to the logged in session$/
   */
  public function iSwitchBackToTheLoggedInSession() {
    if ($this->loggedInUser) {
      $user = User::load($this->loggedInUser);
      if ($user) {
        // Convert Drupal User entity to stdClass
        $account = new \stdClass();
        $account->uid = $user->id();
        $account->name = $user->getAccountName();
        $account->mail = $user->getEmail();
        $account->roles = $user->getRoles();
        $account->pass = $this->newPassword;

        $this->drupalContext->login($account);
        $this->visitPath($this->currentUrl);
      }
      else {
        throw new \Exception("Stored user not found.");
      }
    }
    else {
      throw new \Exception("No stored user to log back in.");
    }
  }

}
