@api @onboarding
Feature: Onboarding, the processed applications
  In order to see what was decided, and by whom
  As a reviewer of onboarding applications
  I want to read the list of processed applications, and each resolution

  Background:
    Given the following onboarding applications:
      | name              | country | status   | reasoning                                          | resolved by     |
      | Aurelia Albertini | Italy   | rejected | Does not belong in the approved list of officials. | Salvatore Monti |
      | Francesca Brunenwald | Germany | approved |                                                | Anje Braun      |
      | Piero Pending     | Italy   | pending  |                                                    |                 |
    And a "RTD admin" account "Rita Admin"
    And a "country reviewer" account "Ivo Italy" for "Italy"
    And a "site admin" account "Sam Siteadmin"

  # The processed list (stories 10.1 and 10.5)

  Scenario: The processed list shows the resolutions, and not the pending applications
    Given I sign in as "Rita Admin"
    When I go to "/onboarding-applications/processed"
    Then I should get a valid web page
    And I should see "Processed onboarding applications"
    And I should see "Aurelia Albertini"
    And I should see "Francesca Brunenwald"
    And I should see "Rejected"
    And I should see "Approved"
    And I should see "Salvatore Monti"
    And I should not see "Piero Pending"

  Scenario: A country reviewer sees the processed applications of their own country only
    Given I sign in as "Ivo Italy"
    When I go to "/onboarding-applications/processed"
    Then I should see "Aurelia Albertini"
    And I should not see "Francesca Brunenwald"

  Scenario Outline: The list is filtered by resolution and by country
    Given I sign in as "Rita Admin"
    When I go to "/onboarding-applications/processed?<filter>"
    Then I should see "<shown>"
    And I should not see "<hidden>"

    Examples:
      | filter              | shown                | hidden               |
      | resolution=rejected | Aurelia Albertini    | Francesca Brunenwald |
      | resolution=approved | Francesca Brunenwald | Aurelia Albertini    |
      | country=IT          | Aurelia Albertini    | Francesca Brunenwald |
      | country=DE          | Francesca Brunenwald | Aurelia Albertini    |

  Scenario: The filters are laid out as in the design
    Given I sign in as "Rita Admin"
    When I go to "/onboarding-applications/processed"
    Then I should see 2 ".views-exposed-form .ecl-row .ecl-col-m-4:first-child" elements
    And I should see 6 ".views-exposed-form .ecl-col-m-4" elements
    And I should see 1 ".views-exposed-form .form-actions" element
    And the filters should be, in this order:
      | Country               |
      | Submitted date - from |
      | Submitted date - to   |
      | Resolution            |
      | Resolution date - from |
      | Resolution date - to  |

  Scenario: The reviewer pages are closed to an anonymous visitor
    Given I am not logged in
    When I go to "/onboarding-applications/processed"
    Then I should get an access denied error

  # The page of a processed application (story 10.2)

  Scenario: A rejected application shows its resolution in a highlight box
    Given I sign in as "Ivo Italy"
    When I go to the application page of "Aurelia Albertini"
    Then I should get a valid web page
    And I should see "Personal information"
    And I should see "Resolution" in the ".ecl-highlight-box__title" element
    And I should see "Rejected" in the ".ecl-highlight-box h4.ecl-u-type-heading-4" element
    And I should see "Application rejected on"
    And I should see "Application rejected on"
    And I should see "by Salvatore Monti"
    And I should see "Does not belong in the approved list of officials."

  Scenario: An approved application shows the word Approved in the box
    Given I sign in as "Rita Admin"
    When I go to the application page of "Francesca Brunenwald"
    Then I should see "Resolution" in the ".ecl-highlight-box__title" element
    And I should see "Approved" in the ".ecl-highlight-box h4.ecl-u-type-heading-4" element
    And I should not see "Application rejected on"

  Scenario: A pending application has no resolution
    Given I sign in as "Rita Admin"
    When I go to the application page of "Piero Pending"
    Then I should see "Personal information"
    And I should see 0 ".ecl-highlight-box" elements

  # Deleting

  Scenario: Only the site administrator may delete an application
    Given I sign in as "Rita Admin"
    When I go to the delete page of the application of "Aurelia Albertini"
    Then I should get an access denied error
    When I sign in as "Ivo Italy"
    And I go to the delete page of the application of "Aurelia Albertini"
    Then I should get an access denied error

  Scenario: The site administrator deletes an application
    Given I sign in as "Sam Siteadmin"
    When I go to the delete page of the application of "Aurelia Albertini"
    Then I should get a valid web page
    When I press "Delete"
    Then the onboarding application of "Aurelia Albertini" should not exist
