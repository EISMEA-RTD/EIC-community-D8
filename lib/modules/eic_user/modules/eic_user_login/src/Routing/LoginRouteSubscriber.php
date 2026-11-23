<?php

namespace Drupal\eic_user_login\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Moves the Drupal login and password forms away from their default paths.
 *
 * Visitors sign in through EU Login, so /user/login and /user/password are no
 * longer offered: the routes are re-pathed, which leaves nothing behind at the
 * old paths (404). The forms stay available at a new location for the local,
 * non-EU Login accounts that still need them.
 *
 * The routes keep their names, so every Url::fromRoute('user.login') and
 * Url::fromRoute('user.pass') follows the new paths without further changes.
 * The one-time login links (/user/reset/...) are left alone: they are the
 * second half of the password reset and are not a place to start from.
 */
class LoginRouteSubscriber extends RouteSubscriberBase {

  /**
   * The path of the Drupal login form.
   */
  const LOGIN_PATH = '/staff-login';

  /**
   * The path of the Drupal password reset form.
   */
  const PASSWORD_PATH = '/staff-login/password';

  /**
   * The routes to move, keyed by route name.
   *
   * The .http routes (JSON login and password reset) share their path with the
   * form routes in core, so they move with them.
   */
  const ROUTE_PATHS = [
    'user.login' => self::LOGIN_PATH,
    'user.login.http' => self::LOGIN_PATH,
    'user.pass' => self::PASSWORD_PATH,
    'user.pass.http' => self::PASSWORD_PATH,
  ];

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    foreach (self::ROUTE_PATHS as $route_name => $path) {
      if ($route = $collection->get($route_name)) {
        $route->setPath($path);
      }
    }
  }

}
