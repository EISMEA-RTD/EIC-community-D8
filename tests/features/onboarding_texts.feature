@api @onboarding
Feature: Onboarding, the texts
  In order to word the messages of the onboarding without a developer
  As a site administrator
  I want to edit the onboarding texts on one form

  Background:
    Given a "site admin" account "Sam Siteadmin"

  Scenario: The administrator edits the messages of the onboarding on one form
    Given I sign in as "Sam Siteadmin"
    When I go to "/admin/config/people/onboarding-texts"
    Then I should get a valid web page
    And I should see "Status messages"
    And I should see "The application was rejected"
    And I should see "The rejected applicant signs in again"

  Scenario: A rejected applicant who signs in again is told that the request was blocked
    Given I sign in as "Sam Siteadmin"
    When I go to "/admin/config/people/onboarding-texts"
    Then the "The rejected applicant signs in again" field should include "Your user request was blocked."
    And the "The rejected applicant signs in again" field should include "contact form"

  Scenario: The form is closed to an applicant
    Given an applicant account "Dora Draft" who has not applied
    And I sign in as "Dora Draft"
    When I go to "/admin/config/people/onboarding-texts"
    Then I should get an access denied error
