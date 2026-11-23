@api @onboarding @eulogin
Feature: Onboarding, a reviewer and their own application
  In order to see what I provided when I applied
  As a reviewer (TVO) who has an application of my own
  I want to view my application, and never to decide it

  # A reviewer may view their own application (the data they provided), but not
  # review it and not delete it. An applicant who is not a reviewer gets nothing.
  # Code: MembershipRequestAccessControlHandler::checkAccess(); the rule is also
  # in the Kernel test MembershipRequestReviewAccessTest. The accounts sign in
  # through the EU Login mock.

  Background:
    Given the following onboarding applications:
      | name           | country | status  | organisation               |
      | Ivo Italy      | Italy   | pending | Torino Academy of Sciences |
      | Gunther Geiger | Germany | pending | Heidelberg University      |
      | Piero Pending  | Italy   | pending | Torino Academy of Sciences |
    And the applicant "Ivo Italy" is a "country reviewer" for "Italy"
    And the following EU Login accounts:
      | username | email                     | first name | last name | local account |
      | n0000010 | ivo_italy@example.com     | Ivo        | Italy     | Ivo Italy     |
      | n0000011 | piero_pending@example.com | Piero      | Pending   | Piero Pending |

  Scenario: A TVO views their own application and sees what they provided
    When I sign in with EU Login as "n0000010"
    And I go to the application page of "Ivo Italy"
    Then I should get a valid web page
    And I should see "Personal information"
    And I should see "Ivo"
    And I should see "Torino Academy of Sciences"
    And I should see "ivo_italy@example.com"

  Scenario: A TVO cannot review or delete their own application
    When I sign in with EU Login as "n0000010"
    And I go to the review page of "Ivo Italy"
    Then I should get an access denied error
    And I should not see a "button:contains('Approve Trusted User')" element
    And I should not see a "button:contains('Reject membership request')" element
    When I go to the delete page of the application of "Ivo Italy"
    Then I should get an access denied error
    And the onboarding application of "Ivo Italy" should be "pending"

  Scenario: A TVO still cannot open an application of another country
    When I sign in with EU Login as "n0000010"
    And I go to the application page of "Gunther Geiger"
    Then I should get an access denied error
    When I go to the review page of "Gunther Geiger"
    Then I should get an access denied error

  Scenario: A TVO still reviews the applications of their own country that are not their own
    When I sign in with EU Login as "n0000010"
    And I go to the review page of "Piero Pending"
    Then I should get a valid web page
    And I should see a "button:contains('Reject membership request')" element

  Scenario: An applicant who is not a reviewer cannot open their own application
    When I sign in with EU Login as "n0000011"
    And I go to the application page of "Piero Pending"
    Then I should get an access denied error
    When I go to the review page of "Piero Pending"
    Then I should get an access denied error
