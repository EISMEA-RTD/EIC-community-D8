@api @onboarding
Feature: Onboarding, the reviewers
  In order to let the right people into the platform
  As a reviewer of onboarding applications
  I want to see the applications that I am responsible for, and to decide them

  Background:
    Given the following onboarding applications:
      | name              | country | status  |
      | Aurelia Albertini | Italy   | pending |
      | Gunther Geiger    | Germany | pending |
    And a "RTD admin" account "Rita Admin"
    And a "country reviewer" account "Ivo Italy" for "Italy"

  # The pending list (story 10.1)

  Scenario: The RTD admin sees the pending applications of every country
    Given I sign in as "Rita Admin"
    When I go to "/onboarding-applications"
    Then I should get a valid web page
    And I should see "Pending onboarding applications"
    And I should see "Aurelia Albertini"
    And I should see "Gunther Geiger"

  Scenario: A country reviewer sees only the applications of their own country
    Given I sign in as "Ivo Italy"
    When I go to "/onboarding-applications"
    Then I should see "Aurelia Albertini"
    And I should not see "Gunther Geiger"

  Scenario: A reviewer without a country sees no application at all
    Given a "country reviewer" account "Nora Nowhere"
    And I sign in as "Nora Nowhere"
    When I go to "/onboarding-applications"
    Then I should get a valid web page
    And I should not see "Aurelia Albertini"
    And I should not see "Gunther Geiger"

  # The review page (stories 10.1, 10.3 and 10.4)

  Scenario: A country reviewer cannot open an application of another country
    Given I sign in as "Ivo Italy"
    When I go to the review page of "Gunther Geiger"
    Then I should get an access denied error
    When I go to the application page of "Gunther Geiger"
    Then I should get an access denied error

  # Story 10.3: an application that is already processed cannot be reviewed again
  Scenario: A reviewer cannot open the review page of an application that is already processed
    Given the following onboarding applications:
      | name         | country | status   | reasoning |
      | Rita Rejected | Italy  | rejected | Not on the list. |
    And I sign in as "Ivo Italy"
    When I go to the review page of "Rita Rejected"
    Then I should get an access denied error

  Scenario: The review page shows the application and the decision box
    Given I sign in as "Ivo Italy"
    When I go to the review page of "Aurelia Albertini"
    Then I should get a valid web page
    And I should see "Review onboarding application"
    And I should see "Personal information"
    And I should see "Aurelia"
    And I should see "Albertini"
    And I should see 1 ".ecl-highlight-box" element
    And I should see "Decision" in the ".ecl-highlight-box__title" element
    And I should see "Reasoning"
    And I should see a "button:contains('Reject membership request')" element
    And I should see a "button:contains('Postpone decision')" element
    And I should see a "button:contains('Approve Trusted User')" element

  Scenario: The two approve buttons have the highlight style of the design
    Given I sign in as "Rita Admin"
    When I go to the review page of "Aurelia Albertini"
    Then I should see 2 "button.ecl-button--primary.ecl-button--highlight" elements

  Scenario: Only the RTD admin is offered to approve as a TVO
    Given I sign in as "Ivo Italy"
    When I go to the review page of "Aurelia Albertini"
    Then I should not see a "button:contains('Approve as TVO')" element
    When I sign in as "Rita Admin"
    And I go to the review page of "Aurelia Albertini"
    Then I should see a "button:contains('Approve as TVO')" element

  Scenario: A TVO cannot approve as a TVO by posting the button by hand
    Given I sign in as "Ivo Italy"
    And I am on the review page of "Aurelia Albertini"
    When I post the review form as if I had pressed "Approve as TVO"
    Then the onboarding application of "Aurelia Albertini" should be "pending"
    And the account of "Aurelia Albertini" should not have the role "country reviewer"

  # The decisions (stories 10.1, 10.2 and 10.4)

  Scenario: Rejecting an application needs a reasoning
    Given I sign in as "Ivo Italy"
    And I am on the review page of "Aurelia Albertini"
    When I press "Reject membership request"
    Then I should see "Reasoning field is required"
    And the onboarding application of "Aurelia Albertini" should be "pending"
    And the account of "Aurelia Albertini" should be active

  Scenario: A rejected application blocks the applicant and keeps the reasoning
    Given I sign in as "Ivo Italy"
    And I am on the review page of "Aurelia Albertini"
    When I fill in "Reasoning" with "Does not belong in the approved list of Italian research security officials."
    And I press "Reject membership request"
    Then the url should match "/onboarding-applications"
    And the onboarding application of "Aurelia Albertini" should be "rejected"
    And the onboarding application of "Aurelia Albertini" should have the reasoning "Does not belong in the approved list of Italian research security officials."
    And the onboarding application of "Aurelia Albertini" should be resolved by "Ivo Italy"
    And the account of "Aurelia Albertini" should be blocked
    And the user status of "Aurelia Albertini" should be "user_rejected"
    When I go to "/onboarding-applications"
    Then I should not see "Aurelia Albertini"
    When I go to "/onboarding-applications/processed"
    Then I should see "Aurelia Albertini"

  Scenario: Approving a Trusted User gives the applicant access to the platform
    Given I sign in as "Ivo Italy"
    And I am on the review page of "Aurelia Albertini"
    When I press "Approve Trusted User"
    Then the onboarding application of "Aurelia Albertini" should be "approved"
    And the onboarding application of "Aurelia Albertini" should be resolved by "Ivo Italy"
    And the account of "Aurelia Albertini" should be active
    And the account of "Aurelia Albertini" should have the role "trusted user"
    And the account of "Aurelia Albertini" should not have the role "country reviewer"
    And the user status of "Aurelia Albertini" should be "user_valid"

  Scenario: The RTD admin can approve an applicant as a TVO
    Given I sign in as "Rita Admin"
    And I am on the review page of "Aurelia Albertini"
    When I press "Approve as TVO"
    Then the onboarding application of "Aurelia Albertini" should be "approved"
    And the account of "Aurelia Albertini" should have the role "country reviewer"
    And the account of "Aurelia Albertini" should have the role "trusted user"

  Scenario: Postponing a decision changes nothing
    Given I sign in as "Ivo Italy"
    And I am on the review page of "Aurelia Albertini"
    When I press "Postpone decision"
    Then the url should match "/onboarding-applications"
    And the onboarding application of "Aurelia Albertini" should be "pending"
    And the account of "Aurelia Albertini" should be active
    When I go to "/onboarding-applications"
    Then I should see "Aurelia Albertini"

  Scenario: An application that was decided cannot be decided again
    Given I sign in as "Ivo Italy"
    And I am on the review page of "Aurelia Albertini"
    And the application of "Aurelia Albertini" is rejected by someone else
    When I fill in "Reasoning" with "Too late."
    And I press "Approve Trusted User"
    Then the onboarding application of "Aurelia Albertini" should be "rejected"
    And the account of "Aurelia Albertini" should not have the role "trusted user"
