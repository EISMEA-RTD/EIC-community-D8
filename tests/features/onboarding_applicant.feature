@api @onboarding
Feature: Onboarding, the applicant
  In order to join the European Due Diligence in Science Platform
  As someone who signed in with EU Login
  I want to make an onboarding application, and to see where it stands

  # Signing in is done with the one-time login link of the account: EU Login
  # cannot be driven from a test. The message a rejected applicant gets when
  # signing in with EU Login is covered by the Kernel tests of the module.

  # Story 1.3
  Scenario: An anonymous visitor is offered the log in and the access information on the front page
    Given I am not logged in
    When I am on the homepage
    Then I should see "About the Due Diligence China portal"
    And I should see "Access to the portal"

  Scenario: An anonymous visitor cannot make an application
    Given I am not logged in
    When I go to "/membership-request"
    Then I should get an access denied error

  # Story 1.1
  Scenario: The page that an invitation links to is open to everybody
    Given I am not logged in
    When I go to "/eddsp_ob"
    Then I should get a valid web page
    And I should see "Restricted access"
    And I should see "Onboarding process"
    And I should see "Log in and apply for onboarding"

  # Story 1.1, step 4: the old address must not be handed out again
  Scenario: The old membership request address is gone
    Given I am not logged in
    When I go to "/about/membership-request"
    Then the response status code should be 404

  # Story 2.1
  Scenario: An account that has not applied is sent to the application form
    Given an applicant account "Dora Draft" who has not applied
    And I sign in as "Dora Draft"
    When I am on the homepage
    Then the url should match "/membership-request"
    And I should see "Personal information"
    And I should see "Submit application"
    And I should see a "button:contains('Submit onboarding application')" element

  # Story 2.1, steps 3 and 4: other pages, and a destination parameter, do not skip the form
  Scenario Outline: An account that has not applied is held on the form whatever it opens
    Given an applicant account "Dora Draft" who has not applied
    And I sign in as "Dora Draft"
    When I go to "<path>"
    Then the url should match "/membership-request"

    Examples:
      | path                  |
      | /contact              |
      | /topics               |
      | /?destination=/user/1 |

  Scenario: An account that has not applied can still read the About pages
    Given an applicant account "Dora Draft" who has not applied
    And I sign in as "Dora Draft"
    When I go to "/about"
    Then I should get a valid web page
    And the url should match "/about"

  # Stories 2.2 and 2.3
  Scenario: An applicant makes an onboarding application
    Given an applicant account "Dora Draft" who has not applied
    And I sign in as "Dora Draft"
    When I go to "/membership-request"
    And I fill in "First name" with "Dora"
    And I fill in "Last name" with "Draft"
    And I fill in "Organisation name" with "Torino Academy of Sciences"
    And I select "Academic institution" from "Organisation type"
    And I fill in "Organisation website" with "torino-academy.example.org"
    And I fill in "Department or division" with "Mathematics"
    And I fill in "Role in the Organisation" with "Research security officer"
    And I select "Italy" from "Country"
    And I check "I agree with the Terms and Conditions"
    And I press "Submit onboarding application"
    Then the onboarding application of "Dora Draft" should be "pending"
    And the user status of "Dora Draft" should be "user_pending"
    And the onboarding application of "Dora Draft" should have the website "https://torino-academy.example.org"
    And I should see "Your onboarding application is undergoing review"

  # Story 2.3, step 3: one request per applicant, ever. The address does not give
  # access denied (the story says so, the page of ApplyController says why not):
  # it tells the applicant where the application stands, and offers no form.
  Scenario: An applicant who has applied is told where the application stands, and gets no second form
    Given the following onboarding applications:
      | name          | country | status  |
      | Piero Pending | Italy   | pending |
    And I sign in as "Piero Pending"
    When I go to "/membership-request"
    Then I should see "Your onboarding application is undergoing review"
    And I should not see a "button:contains('Submit onboarding application')" element
    And I should not see "Personal information"
    And the onboarding application of "Piero Pending" should be "pending"

  # Story 2.2, step 4
  Scenario: The application cannot be submitted without the required fields
    Given an applicant account "Dora Draft" who has not applied
    And I sign in as "Dora Draft"
    When I go to "/membership-request"
    And I press "Submit onboarding application"
    Then I should see "First name field is required"
    And I should see "Last name field is required"
    And I should see "Organisation name field is required"
    And the onboarding application of "Dora Draft" should not exist

  # Story 3.1, step 1
  Scenario Outline: An applicant whose application is under review can use the open pages
    Given the following onboarding applications:
      | name          | country | status  |
      | Piero Pending | Italy   | pending |
    And I sign in as "Piero Pending"
    When I go to "<path>"
    Then I should get a valid web page
    And the url should match "<path>"

    Examples:
      | path     |
      | /about   |
      | /contact |

  # Story 3.1
  Scenario: An applicant whose application is under review sees the public front page, without the cards
    Given the following onboarding applications:
      | name        | country | status  |
      | Piero Pending | Italy | pending |
    And I sign in as "Piero Pending"
    When I am on the homepage
    Then I should see "About the Due Diligence China portal"
    And I should not see "Access to the portal"
    And I should not see "Personal information"

  Scenario: The front page is not served to the wrong visitor once it is cached
    # An anonymous visitor fills the page cache, a trusted member must not get
    # that copy, and the visitor must not get the member's.
    Given the following onboarding applications:
      | name          | country | status   |
      | Anna Approved | Germany | approved |
    And I am not logged in
    When I am on the homepage
    Then I should see "Access to the portal"
    When I sign in as "Anna Approved"
    And I am on the homepage
    Then I should not see "Access to the portal"
    When I am not logged in
    And I am on the homepage
    Then I should see "Access to the portal"

  # Story 3.1, step 2, and story 10.3
  Scenario Outline: An applicant whose application is under review has no more access than a visitor
    Given the following onboarding applications:
      | name          | country | status  |
      | Piero Pending | Italy   | pending |
    And I sign in as "Piero Pending"
    When I go to "<path>"
    Then I should get an access denied error

    Examples:
      | path                  |
      | /data-explorer        |
      | /analyses-reports     |
      | /network              |
      | /onboarding-applications |
      | /onboarding-applications/processed |

  Scenario: An applicant cannot read their own application, or review it
    Given the following onboarding applications:
      | name          | country | status  |
      | Piero Pending | Italy   | pending |
    And I sign in as "Piero Pending"
    When I go to the application page of "Piero Pending"
    Then I should get an access denied error
    When I go to the review page of "Piero Pending"
    Then I should get an access denied error

  # Story 4.1: the access arrives in the session that is already open
  Scenario: An approval gives access in the session that is already open
    Given the following onboarding applications:
      | name          | country | status  |
      | Piero Pending | Italy   | pending |
    And I sign in as "Piero Pending"
    And I go to "/data-explorer"
    And I should get an access denied error
    When the application of "Piero Pending" is approved by someone else
    And I go to "/data-explorer"
    Then I should get a valid web page

  # Story 5.1: the session of a rejected applicant is gone on the next click
  Scenario: A rejection ends the session that is open
    Given the following onboarding applications:
      | name          | country | status  |
      | Piero Pending | Italy   | pending |
    And I sign in as "Piero Pending"
    And I am on the homepage
    And I should not see "Access to the portal"
    When the application of "Piero Pending" is rejected by someone else
    And I am on the homepage
    Then I should see "Access to the portal"
    And the account of "Piero Pending" should be blocked

  # Story 5.1
  Scenario: A rejected application blocks the account
    Given the following onboarding applications:
      | name         | country | status   | reasoning                         |
      | Rita Rejected | Italy  | rejected | Does not belong in the list.      |
    Then the account of "Rita Rejected" should be blocked
    And the user status of "Rita Rejected" should be "user_rejected"
    And the account of "Rita Rejected" should not have the role "trusted user"

  # Story 4.1
  Scenario: An approved application gives the account access to the platform
    Given the following onboarding applications:
      | name          | country | status   |
      | Anna Approved | Germany | approved |
    Then the account of "Anna Approved" should be active
    And the account of "Anna Approved" should have the role "trusted user"
    And the user status of "Anna Approved" should be "user_valid"
    When I sign in as "Anna Approved"
    And I go to "/data-explorer"
    Then I should get a valid web page
