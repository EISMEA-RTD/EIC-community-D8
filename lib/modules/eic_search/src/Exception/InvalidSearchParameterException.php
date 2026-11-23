<?php

namespace Drupal\eic_search\Exception;

/**
 * Thrown when a parameter of a search request is not what the search expects.
 *
 * The message names the parameter, never its value, so that it can be shown
 * to the caller.
 */
class InvalidSearchParameterException extends \InvalidArgumentException {

}
