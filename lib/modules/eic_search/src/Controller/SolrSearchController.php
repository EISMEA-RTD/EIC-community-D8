<?php

namespace Drupal\eic_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ddc_default_content\RestrictedContentPolicy;
use Drupal\eic_search\Exception\InvalidSearchParameterException;
use Drupal\eic_search\Search\Sources\SourceTypeInterface;
use Drupal\eic_search\Service\SolrSearchManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class SolrSearchController
 *
 * @package Drupal\eic_groups\Controller
 */
class SolrSearchController extends ControllerBase {

  /**
   * @var \Drupal\eic_search\Service\SolrSearchManager
   *   The solr search manager service.
   */
  private SolrSearchManager $searchManager;

  /**
   * @var \Drupal\ddc_default_content\RestrictedContentPolicy
   *   The restricted content policy.
   */
  private RestrictedContentPolicy $restrictedContentPolicy;

  /**
   * @var \Psr\Log\LoggerInterface
   *   The logger of the module.
   */
  private LoggerInterface $logger;

  public function __construct(SolrSearchManager $solr_search_manager, RestrictedContentPolicy $restricted_content_policy, LoggerInterface $logger) {
    $this->searchManager = $solr_search_manager;
    $this->restrictedContentPolicy = $restricted_content_policy;
    $this->logger = $logger;
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *
   * @return \Drupal\eic_search\Controller\SolrSearchController
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('eic_search.solr_search_manager'),
      $container->get('ddc_default_content.restricted_content'),
      $container->get('logger.factory')->get('eic_search')
    );
  }

  /**
   * Answers a search of the overview pages.
   *
   * No text of the request reaches Solr as query syntax: numbers must be
   * numbers, facet values and the search text are escaped, and facet fields
   * and sorts must be declared by the source (SolrSearchManager). A parameter
   * that is not what the search expects (an array where a value is expected, a
   * page below 1, a request beyond the result window) gets a 400 and no search,
   * and is not logged: any caller can send one. A failure of Solr is logged and
   * gets a generic 500, so that no query or field name of the index reaches the
   * caller.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function search(Request $request) {
    try {
      // A request without a source class is as invalid as an unknown one.
      $source_class = $this->getScalarParameter($request, 'source_class') ?? '';
      $search_value = $this->getScalarParameter($request, 'search_value', '');
      $current_group = $this->getNumericParameter($request, 'current_group');
      $topic_term_id = $this->getNumericParameter($request, 'topics');
      $facets_value = $this->getFacetsValue($request);
      $sort_value = $this->getScalarParameter($request, 'sort_value');
      $user_id_from_route = (int) $this->getNumericParameter($request, 'userIdFromRoute', TRUE);
      $facets_options = $this->getFacetsOptions($request);
      // Timestamp value, if nothing set "*" (the default value on solr).
      $from_date = $this->getScalarParameter($request, 'from_date', '*');
      $end_date = $this->getScalarParameter($request, 'end_date', '*');
      // Numeric range bounds (e.g. publication year), if nothing set "*".
      $range_from = $this->getScalarParameter($request, 'year_from', '*');
      $range_to = $this->getScalarParameter($request, 'year_to', '*');
      $page = (int) ($this->getNumericParameter($request, 'page') ?? 1);
      $offset = (int) ($this->getNumericParameter($request, 'offset') ?? SourceTypeInterface::READ_MORE_NUMBER_TO_LOAD);

      $search = $this->searchManager->init($source_class);

      // Check if source exists.
      $source = $this->searchManager->getSource();
      if (!$source) {
        return new JsonResponse([
          'error' => 'invalid_source',
          'message' => 'Invalid or unknown source class.',
        ], Response::HTTP_BAD_REQUEST);
      }

      // Sources of restricted content only are served to trusted accounts. The
      // other sources are filtered per item in SolrSearchManager::search().
      if ($source->requiresRestrictedAccess() && !$this->restrictedContentPolicy->isTrusted($this->currentUser())) {
        if ($this->currentUser()->isAnonymous()) {
          return new JsonResponse([
            'error' => 'authentication_required',
            'message' => 'You must be logged in to access this content.',
          ], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
          'error' => 'restricted_access_required',
          'message' => 'Your account does not have access to this content.',
        ], Response::HTTP_FORBIDDEN);
      }

      $search->buildUserIdFromUrl($user_id_from_route);
      $search->buildGroupQuery($current_group);
      $search->buildFacets($facets_options);
      $search->buildSortFacets($facets_value, $sort_value);
      $search->buildDateQuery($from_date, $end_date);
      $search->buildRangeQuery($range_from, $range_to);
      $search->buildPrefilterTopic($topic_term_id === NULL ? NULL : (int) $topic_term_id);
      $search->buildQueryPager($page, $offset);
      $search->buildSearchQuery($search_value);

      return new Response($search->search(), Response::HTTP_OK, [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ]);
    }
    catch (InvalidSearchParameterException $e) {
      return new JsonResponse([
        'error' => 'invalid_parameter',
        'message' => 'Invalid search parameter: ' . $e->getMessage() . '.',
      ], Response::HTTP_BAD_REQUEST);
    }
    catch (\Throwable $e) {
      $this->logger->error('The Solr search failed: @class: @message', [
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'search_failed',
        'message' => 'The search failed.',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Returns a parameter of the request that must be a single value.
   *
   * Request::$query->get() throws for an array (search_value[]=x), which would
   * end as a logged 500 of the search; here it is a refused parameter.
   *
   * @param string|null $default
   *   The value when the parameter is not set.
   *
   * @throws \Drupal\eic_search\Exception\InvalidSearchParameterException
   *   When the parameter is an array.
   */
  private function getScalarParameter(Request $request, string $name, ?string $default = NULL): ?string {
    $value = $request->query->all()[$name] ?? $default;
    if ($value === NULL) {
      return NULL;
    }
    if (!is_scalar($value)) {
      throw new InvalidSearchParameterException($name);
    }

    return (string) $value;
  }

  /**
   * Returns a numeric parameter of the request, or NULL when it is not set.
   *
   * @param bool $allow_negative
   *   Whether a negative number is accepted (the client sends -1 for "no user
   *   from the route").
   *
   * @throws \Drupal\eic_search\Exception\InvalidSearchParameterException
   */
  private function getNumericParameter(Request $request, string $name, bool $allow_negative = FALSE): ?string {
    $value = $this->getScalarParameter($request, $name);
    if ($value === NULL || $value === '') {
      return NULL;
    }

    $pattern = $allow_negative ? '/^-?\d+$/' : '/^\d+$/';
    if (!preg_match($pattern, $value)) {
      throw new InvalidSearchParameterException($name);
    }

    return $value;
  }

  /**
   * Returns the facet fields of the request: a list of field names.
   *
   * @throws \Drupal\eic_search\Exception\InvalidSearchParameterException
   */
  private function getFacetsOptions(Request $request): array {
    $facets_options = $request->query->all()['facets_options'] ?? [];
    if (!is_array($facets_options)) {
      throw new InvalidSearchParameterException('facets_options');
    }
    foreach ($facets_options as $facet) {
      if (!is_string($facet)) {
        throw new InvalidSearchParameterException('facets_options');
      }
    }

    return $facets_options;
  }

  /**
   * Returns the facet values of the request: a JSON object of value lists.
   *
   * @throws \Drupal\eic_search\Exception\InvalidSearchParameterException
   */
  private function getFacetsValue(Request $request): array {
    $json = $this->getScalarParameter($request, 'facets_value');
    if ($json === NULL || $json === '') {
      return [];
    }

    $facets_value = json_decode($json, TRUE);
    if (!is_array($facets_value)) {
      throw new InvalidSearchParameterException('facets_value');
    }
    foreach ($facets_value as $values) {
      if (!is_array($values)) {
        throw new InvalidSearchParameterException('facets_value');
      }
      foreach ($values as $selected) {
        if (!is_scalar($selected) && $selected !== NULL) {
          throw new InvalidSearchParameterException('facets_value');
        }
      }
    }

    return $facets_value;
  }

}
