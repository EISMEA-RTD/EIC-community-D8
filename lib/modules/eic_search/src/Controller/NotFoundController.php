<?php

namespace Drupal\eic_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers 404 for a route that is switched off but must keep existing.
 *
 * @see \Drupal\eic_search\Routing\GlobalSearchRouteSubscriber
 */
class NotFoundController extends ControllerBase {

  /**
   * Throws a "not found" for the requested page.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  public function notFound() {
    throw new NotFoundHttpException();
  }

}
