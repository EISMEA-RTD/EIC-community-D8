<?php

namespace Drupal\eic_search\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Turns the global search page (/search) off.
 *
 * The page answers 404 for everybody, whatever their permissions. The route is
 * kept, under its name, because other code builds links from it
 * (Url::fromRoute('eic_search.global_search')) and would throw if it were gone.
 * The permission requirement is dropped on purpose: with it an untrusted
 * visitor would be told "access denied" (403) instead of "not found".
 *
 * To bring the page back, remove this subscriber (or its service tag).
 */
class GlobalSearchRouteSubscriber extends RouteSubscriberBase {

  /**
   * The route of the global search page.
   */
  const ROUTE_NAME = 'eic_search.global_search';

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get(self::ROUTE_NAME)) {
      $route->setDefault('_controller', '\Drupal\eic_search\Controller\NotFoundController::notFound');
      $route->setRequirements(['_access' => 'TRUE']);
    }
  }

}
