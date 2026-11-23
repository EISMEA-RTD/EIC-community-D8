@api @onboarding @eulogin
Feature: Onboarding, signing in with EU Login
  In order to get into the platform with my EU Login account
  As someone who signs in with EU Login
  I want the site to tell me what to do next, whatever the state of my application

  # EU Login is the mock of OpenEuropa (cas_mock_server and
  # oe_authentication_eulogin_mock), enabled for these scenarios only. The
  # scenarios walk the real path: the log in address, the mock EU Login form,
  # and the ticket that the site validates on the way back.

  Scenario: A person who signs in for the first time is held on the application form
    Given the following EU Login accounts:
      | username | email                | first name | last name |
      | n0000001 | newcomer@example.com | Nora       | Newcomer  |
    When I sign in with EU Login as "n0000001"
    Then the url should match "/membership-request"
    And I should see "Submit onboarding application"
    And the user status of "n0000001" should be "user_draft"
    And the account of "n0000001" should not have the role "trusted user"

  Scenario: An applicant whose application is under review is told so
    Given the following onboarding applications:
      | name          | country | status  |
      | Piero Pending | Italy   | pending |
    And the following EU Login accounts:
      | username | email                     | first name | last name | local account |
      | n0000002 | piero_pending@example.com | Piero      | Pending   | Piero Pending |
    When I sign in with EU Login as "n0000002"
    Then I should see "Your onboarding application is undergoing review"
    And I should not see "Access to the portal"
    When I go to "/data-explorer"
    Then I should get an access denied error

  Scenario: An approved applicant gets into the platform
    Given the following onboarding applications:
      | name          | country | status   |
      | Anna Approved | Germany | approved |
    And the following EU Login accounts:
      | username | email                     | first name | last name | local account |
      | n0000003 | anna_approved@example.com | Anna       | Approved  | Anna Approved |
    When I sign in with EU Login as "n0000003"
    Then I should see "Welcome"
    When I go to "/data-explorer"
    Then I should get a valid web page

  Scenario: A rejected applicant is told that the application was rejected and the account is blocked
    Given the following onboarding applications:
      | name          | country | status   | reasoning                |
      | Rita Rejected | Italy   | rejected | Not on the official list |
    And the following EU Login accounts:
      | username | email                     | first name | last name | local account |
      | n0000004 | rita_rejected@example.com | Rita       | Rejected  | Rita Rejected |
    When I sign in with EU Login as "n0000004"
    Then I should see "Your onboarding application was rejected. Your user account is blocked."
    And I should not see "Not on the official list"
    And I should not see "already exists"
    # Not signed in: an anonymous visitor is sent from a restricted page to /about.
    When I go to "/data-explorer"
    Then the url should match "/about"

  # The case that was reported: the EU Login ID is not the name that the account
  # is stored under, so CAS tries to register a new account, and the e-mail
  # address is taken by the blocked account of the rejected application.
  Scenario: A rejected applicant who signs in again under another EU Login ID is told that the request was blocked
    Given the following onboarding applications:
      | name          | country | status   | reasoning                |
      | Rita Rejected | Italy   | rejected | Not on the official list |
    And the following EU Login accounts:
      | username | email                     | first name | last name |
      | n0000005 | rita_rejected@example.com | Rita       | Rejected  |
    When I sign in with EU Login as "n0000005"
    Then I should see "Your user request was blocked."
    And I should see the link "contact form"
    And I should not see "A user with this email address already exists"
    And the account of "Rita Rejected" should be blocked

  Scenario: The contact form link in that message leads to the contact form
    Given the following onboarding applications:
      | name          | country | status   |
      | Rita Rejected | Italy   | rejected |
    And the following EU Login accounts:
      | username | email                     | first name | last name |
      | n0000005 | rita_rejected@example.com | Rita       | Rejected  |
    When I sign in with EU Login as "n0000005"
    And I click "contact form"
    Then the url should match "/contact"

  Scenario: The e-mail address of any other account keeps the message of EU Login
    Given the following onboarding applications:
      | name          | country | status  |
      | Piero Pending | Italy   | pending |
    And the following EU Login accounts:
      | username | email                     | first name | last name |
      | n0000006 | piero_pending@example.com | Piero      | Pending   |
    When I sign in with EU Login as "n0000006"
    Then I should see "A user with this email address already exists"
    And I should not see "Your user request was blocked."
