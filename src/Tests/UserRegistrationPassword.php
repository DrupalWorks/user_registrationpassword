<?php

namespace Drupal\user_registrationpassword\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Functionality tests for User registration password module.
 *
 * @group UserRegistrationPassword.
 */
class UserRegistrationPassword extends WebTestBase {

  /**
   * Modules to install.
   *
   * @var array
   */
  // TODO why is field test required?
  public static $modules = array('field_test', 'user_registrationpassword');

  /**
   * Implements testRegistrationWithEmailVerificationAndPassword().
   */
  function testRegistrationWithEmailVerificationAndPassword() {
    // Disable e-mail verification.
    \Drupal::configFactory()->getEditable('user.settings')->set('verify_mail', FALSE)->save();
    // Prevent standard notification email to administrators and to user.
    //variable_set('user_mail_register_pending_approval_notify', FALSE);
    // Set the registration variable to 2, register with pass, but require confirmation.
    //variable_set('user_registrationpassword_registration', USER_REGISTRATIONPASS_VERIFICATION_PASS);

    // Register a new account.
    $edit = array();
    $edit['name'] = $name = $this->randomMachineName();
    $edit['mail'] = $mail = $edit['name'] . '@example.com';
    $edit['pass[pass1]'] = $new_pass = $this->randomMachineName();
    $edit['pass[pass2]'] = $new_pass;
    $pass = $new_pass;
    $this->drupalPostForm('user/register', $edit, 'Create new account');
    $this->assertText('In the meantime, a welcome message with further instructions has been sent to your email address.', 'User registered successfully.');

    // Load the new user.
    $accounts = \Drupal::entityQuery('user')
      ->condition('name', $name)
      ->condition('mail', $mail)
      ->condition('status', 0)
      ->execute();
    /** @var \Drupal\user\UserInterface $account */
    $account = \Drupal::entityTypeManager()->getStorage('user')->load(reset($accounts));

    // Configure some timestamps.
    // We up the timestamp a bit, else the check will fail.
    // The function that checks this uses the execution time
    // and that's always larger in real-life situations
    // (and it fails correctly when you remove the + 5000).
    $timestamp = REQUEST_TIME + 5000;
    $test_timestamp = REQUEST_TIME;
    $bogus_timestamp = REQUEST_TIME - 86500;

    // check if the account has not been activated.
    $this->assertFalse($account->isActive(), 'New account is blocked until approved via e-mail confirmation. status check.');
    $this->assertFalse($account->getLastLoginTime(), 'New account is blocked until approved via e-mail confirmation. login check.');
    $this->assertFalse($account->getLastAccessedTime(), 'New account is blocked until approved via e-mail confirmation. access check.');

    // Login before activation.
    $auth = array(
      'name' => $name,
      'pass' => $pass,
    );
    $this->drupalPostForm('user/login', $auth, 'Log in');
    $this->assertText('The username ' . $name . ' has not been activated or is blocked.', 'User cannot login yet.');

    // Timestamp can not be smaller then current. (== registration time).
    // If this is the case, something is really wrong.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$test_timestamp/" . user_pass_rehash($account, $test_timestamp));
    $this->assertText('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.');

    // Fake key combi.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account, $bogus_timestamp));
    $this->assertText('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.');

    // Fake timestamp.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$bogus_timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertText('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.');

    // Wrong password.
    // TODO
    //$this->drupalGet("user/registrationpassword/$account->uid/$bogus_timestamp/" . user_pass_rehash($this->randomMachineName(), $timestamp, $account->name, $account->uid));
    //$this->assertText(t('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.'));

    // Attempt to use the activation link.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertText('You have just used your one-time login link. Your account is now active and you are authenticated.');

    // Attempt to use the activation link again.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertText('You are currently authenticated as user ' . $account->getAccountName() . '.');

    // Logout the user
    $this->drupalLogout();

    // Then attempt to use the activation link yet again.
    $this->drupalGet("user/registrationpassword/" . $account->id() . "/$timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertText('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.');

    // And then try to do normal login.
    $auth = array(
      'name' => $name,
      'pass' => $pass,
    );
    $this->drupalPostForm('user/login', $auth, 'Log in');
    $this->assertText('Member for', 'User logged in.');
  }

  function _testPasswordResetFormResendActivation() {
    // Allow registration by site visitors without administrator
    // approval and set password during registration.
    variable_set('user_register', USER_REGISTER_VISITORS);
    // Disable e-mail verification.
    variable_set('user_email_verification', FALSE);
    // Prevent standard notification email to administrators and to user.
    variable_set('user_mail_register_pending_approval_notify', FALSE);
    // Set the registration variable to 2, register with pass, but require confirmation.
    variable_set('user_registrationpassword_registration', USER_REGISTRATIONPASS_VERIFICATION_PASS);

    // Register a new account.
    $edit1 = array();
    $edit1['name'] = $name = $this->randomMachineName();
    $edit1['mail'] = $mail = $edit1['name'] . '@example.com';
    $edit1['pass[pass1]'] = $new_pass = $this->randomMachineName();
    $edit1['pass[pass2]'] = $new_pass;
    $pass = $new_pass;
    $this->drupalPostForm('user/register', $edit1, t('Create new account'));
    $this->assertText(t('A welcome message with further instructions has been sent to your e-mail address.'), t('User registered successfully.'));

    // Load the new user.
    $accounts = user_load_multiple(array(), array('name' => $name, 'mail' => $mail, 'status' => 0));
    $account = reset($accounts);

    // Request a new activation e-mail.
    $edit2 = array();
    $edit2['name'] = $edit1['name'];
    $this->drupalPostForm('user/password', $edit2, t('E-mail new password'));
    $this->assertText(t('Further instructions have been sent to your e-mail address.'), t('Password rest form submitted successfully.'));

    // Request a new activation e-mail for a non-existing user name.
    $edit3 = array();
    $edit3['name'] = $this->randomMachineName();
    $this->drupalPostForm('user/password', $edit3, t('E-mail new password'));
    $this->assertText(t('Sorry, !name is not recognized as a user name or an e-mail address.', array('!name' => $edit3['name'])), t('Password rest form failed correctly.'));

    // Request a new activation e-mail for a non-existing user e-mail.
    $edit4 = array();
    $edit4['name'] = $this->randomMachineName() . '@example.com';
    $this->drupalPostForm('user/password', $edit4, t('E-mail new password'));
    $this->assertText(t('Sorry, !mail is not recognized as a user name or an e-mail address.', array('!mail' => $edit4['name'])), t('Password rest form failed correctly.'));
  }

  function _testLoginWithUrpLinkWhileBlocked() {
    // Allow registration by site visitors without administrator
    // approval and set password during registration.
    variable_set('user_register', USER_REGISTER_VISITORS);
    // Disable e-mail verification.
    variable_set('user_email_verification', FALSE);
    // Prevent standard notification email to administrators and to user.
    variable_set('user_mail_register_pending_approval_notify', FALSE);
    // Set the registration variable to 2, register with pass, but require confirmation.
    variable_set('user_registrationpassword_registration', USER_REGISTRATIONPASS_VERIFICATION_PASS);

    $timestamp = REQUEST_TIME + 5000;

    // Register a new account.
    $edit = array();
    $edit['name'] = $name = $this->randomMachineName();
    $edit['mail'] = $mail = $edit['name'] . '@example.com';
    $edit['pass[pass1]'] = $new_pass = $this->randomMachineName();
    $edit['pass[pass2]'] = $new_pass;
    $pass = $new_pass;
    $this->drupalPostForm('user/register', $edit, t('Create new account'));
    $this->assertText(t('A welcome message with further instructions has been sent to your e-mail address.'), t('User registered successfully.'));

    // Load the new user.
    $accounts = user_load_multiple(array(), array('name' => $name, 'mail' => $mail, 'status' => 0));
    $account = reset($accounts);

    // Attempt to use the activation link.
    $this->drupalGet("user/registrationpassword/$account->uid/$timestamp/" . user_pass_rehash($account->pass, $timestamp, $account->name, $account->uid));
    $this->assertText(t('You have just used your one-time login link. Your account is now active and you are authenticated.'));

    // Logout the user.
    $this->drupalLogout();

    // Block the user.
    $admin_user = $this->drupalCreateUser(array('administer users'));
    $this->drupalLogin($admin_user);
    $this->drupalGet('admin/people');
    $editblock = array();
    $editblock['operation'] = 'block';
    $editblock['accounts[' . $account->uid . ']'] = TRUE;
    $this->drupalPostForm('admin/people', $editblock, t('Update'));

    // Logout the administrator.
    $this->drupalLogout();

    // Load the new user.
    $accounts_blocked = user_load_multiple(array(), array('name' => $name, 'mail' => $mail, 'status' => 0));
    $account_blocked = reset($accounts_blocked);

    // Confirm status is really blocked.
    $this->assertEqual($account_blocked->status, 0, 'User blocked');

    // Then try to use the link.
    $this->drupalGet("user/registrationpassword/$account->uid/$timestamp/" . user_pass_rehash($account->pass, $timestamp, $account->name, $account->uid));
    $this->assertText(t('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.'));

    // Try to request a new activation e-mail.
    $edit2['name'] = $edit['name'];
    $this->drupalPostForm('user/password', $edit2, t('E-mail new password'));
    $this->assertText(t('Sorry, !name is not recognized as a user name or an e-mail address.', array('!name' => $edit2['name'])), t('Password rest form failed correctly.'));
  }
}
