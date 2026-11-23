Feature: User authentication
  In order to protect the integrity of the website
  As a product owner
  I want to make sure users with various roles can only access pages they are authorized to

  # The login page is the staff login: "user" leads to /staff-login, and the
  # default Drupal login and registration paths are switched off.
  Scenario: Anonymous user can see the staff login page
    Given I am not logged in
    When I visit "user"
    Then the url should match "/staff-login"
    And I should see the text "Username"
    And I should see the text "Password"
    But I should not see the text "Log out"
    And I should not see the text "View profile"

  Scenario Outline: Anonymous user can access public pages
    Given I am not logged in
    # By visiting the path an access check is done automatically.
    Then I visit "<path>"

    Examples:
      | path                  |
      | staff-login           |
      | staff-login/password  |
      | user/register         |

  Scenario Outline: The default Drupal login paths are switched off
    Given I am not logged in
    When I go to "<path>"
    Then the response status code should be 404

    Examples:
      | path          |
      | user/login    |
      | user/password |

  # The search page is off. It is not a 404 any more (the address belongs to an
  # overview page that nobody may open): what counts is that it is closed.
  Scenario: The search page is closed
    Given I am not logged in
    When I go to "search"
    Then I should get an access denied error

  Scenario Outline: Anonymous user cannot access restricted pages
    Given I am not logged in
    When I go to "<path>"
    Then I should get an access denied error

    Examples:
      | path             |
      | admin            |
      | admin/appearance |
      | admin/config     |
      | admin/content    |
      | admin/people     |
      | admin/structure  |
      # To do: the 'node/' path should be disabled on most Drupal sites.
      # | node            |
      | node/add         |
      | user/1           |

  @api
  Scenario Outline: Authenticated user can access pages they are authorized to
    Given I am logged in as a user with the "authenticated" role
    Then I visit "<path>"

    Examples:
      | path |
      | user |

  @api
  Scenario Outline: Authenticated user cannot access site administration
    Given I am logged in as a user with the "authenticated" role
    When I go to "<path>"
    Then I should get an access denied error

    Examples:
      | path             |
      | admin            |
      | admin/appearance |
      | admin/config     |
      | admin/content    |
      | admin/people     |
      | admin/structure  |
      # To do: the 'node/' path should be disabled on most Drupal sites.
      # | node            |
      | node/add         |
      | user/1           |
