<?php

namespace Drupal\eic_search\Service;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ddc_default_content\RestrictedContentPolicy;
use Drupal\eic_groups\EICGroupsHelper;
use Drupal\eic_search\Exception\InvalidSearchParameterException;
use Drupal\eic_search\Collector\SourcesCollector;
use Drupal\eic_search\Plugin\search_api\processor\GroupAccessContent;
use Drupal\eic_search\Search\DocumentProcessor\DocumentProcessorInterface;
use Drupal\eic_search\Search\Sources\GlobalSourceType;
use Drupal\eic_search\Search\Sources\GroupEventSourceType;
use Drupal\eic_search\Search\Sources\GroupSourceType;
use Drupal\eic_search\Search\Sources\NewsStorySourceType;
use Drupal\eic_search\Search\Sources\OrganisationSourceType;
use Drupal\eic_search\Search\Sources\SourceTypeInterface;
use Drupal\eic_search\Search\Sources\UserGallerySourceType;
use Drupal\eic_search\Search\Sources\UserInvitesListSourceType;
use Drupal\eic_search\Search\Sources\UserListSourceType;
use Drupal\eic_search\Search\Sources\UserRecommendSourceType;
use Drupal\eic_search\Search\Sources\UserTaggingCommentsSourceType;
use Drupal\eic_search\SearchHelper;
use Drupal\eic_topics\Constants\Topics;
use Drupal\eic_user\UserHelper;
use Drupal\group\Entity\Group;
use Drupal\group\GroupMembership;
use Drupal\group\GroupMembershipLoaderInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_solr\SolrConnectorInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Solarium\Component\ComponentAwareQueryInterface;
use Solarium\QueryType\Select\Query\Query;

class SolrSearchManager {

  /**
   * The furthest position in a result list that a request may reach.
   *
   * A request reaches the position page * offset: the page ends there, and a
   * "load more" list is that long (it loads everything up to the page). The
   * overview blocks send at most 100 results per page (the page size selector)
   * or a load more batch of 20, so that this leaves room for lists of 50 times
   * the biggest page and for 250 batches of "load more"; a request beyond it is
   * not one of the blocks.
   */
  const MAX_RESULT_WINDOW = 5000;

  /**
   * @var AccountProxyInterface
   */
  private AccountProxyInterface $currentUser;

  /**
   * @var \Drupal\eic_search\Collector\SourcesCollector
   */
  private SourcesCollector $sourcesCollector;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  private EntityTypeManager $em;

  /**
   * @var \Drupal\group\GroupMembershipLoaderInterface
   */
  private GroupMembershipLoaderInterface $membershipLoader;

  /**
   * @var \Drupal\ddc_default_content\RestrictedContentPolicy
   */
  private RestrictedContentPolicy $restrictedContentPolicy;

  /**
   * @var \Drupal\eic_search\Search\Sources\SourceTypeInterface|null
   */
  private ?SourceTypeInterface $source;

  /**
   * @var array
   */
  private array $facets;

  /**
   * @var array
   */
  private array $interests;

  /**
   * @var \Solarium\QueryType\Select\Query\Query
   */
  private Query $solrQuery;

  /**
   * @var Index
   */
  private Index $index;

  /**
   * @var string|null
   */
  private ?string $currentGroup;

  /**
   * @var int|null
   */
  private ?int $userIdFromRoute;

  /**
   * @var \Drupal\search_api_solr\SolrConnectorInterface
   */
  private SolrConnectorInterface $connector;

  /**
   * 'fq' value in solr.
   *
   * @var string
   */
  private string $rawFieldQuery;

  /**
   * 'q' value in solr.
   *
   * @var string
   */
  private string $rawQuery;

  /**
   * @param AccountProxyInterface $current_user
   * @param \Drupal\eic_search\Collector\SourcesCollector $sources_collector
   * @param \Drupal\Core\Entity\EntityTypeManager $em
   * @param \Drupal\group\GroupMembershipLoaderInterface $membership_loader
   * @param \Drupal\ddc_default_content\RestrictedContentPolicy $restricted_content_policy
   */
  public function __construct(
    AccountProxyInterface $current_user,
    SourcesCollector $sources_collector,
    EntityTypeManager $em,
    GroupMembershipLoaderInterface $membership_loader,
    RestrictedContentPolicy $restricted_content_policy,
  ) {
    $this->currentUser = $current_user;
    $this->sourcesCollector = $sources_collector;
    $this->em = $em;
    $this->membershipLoader = $membership_loader;
    $this->restrictedContentPolicy = $restricted_content_policy;
  }

  /**
   * @param string $source_class
   * @param array|null $facets_fields
   *
   * @return $this
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function init(string $source_class): self {
    $index_storage = $this->em
      ->getStorage('search_api_index');
    $this->index = $index_storage->load('global');
    $this->currentGroup = NULL;

    /** @var \Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend $backend */
    $backend = $this->index->getServerInstance()->getBackend();
    $config = $backend->getConfiguration();
    $backend->setConfiguration($config);
    /** @var \Drupal\search_api_solr\Plugin\SolrConnector\BasicAuthSolrConnector $connector */
    $this->connector = $backend->getSolrConnector();
    $this->solrQuery = $this->connector->getSelectQuery();
    $this->rawQuery = '';

    $sources = $this->sourcesCollector->getSources();
    $this->source = array_key_exists($source_class, $sources) ? $sources[$source_class] : NULL;

    // Return early if source is not found - caller should check getSource().
    if (!$this->source) {
      $this->rawFieldQuery = '';
      return $this;
    }

    $datasources = $this->source->getSourcesId();
    $datasources_query = [];

    foreach ($datasources as $datasource) {
      $datasources_query[] = 'ss_search_api_datasource:"entity:' . $datasource . '"';
    }

    $this->rawFieldQuery = '(' . implode(' OR ', $datasources_query) . ')';

    return $this;
  }

  /**
   * Gets the current source type.
   *
   * @return \Drupal\eic_search\Search\Sources\SourceTypeInterface|null
   *   The current source type or NULL if not initialized.
   */
  public function getSource(): ?SourceTypeInterface {
    return $this->source ?? NULL;
  }

  /**
   * Set user id from url if needed to build query.
   *
   * @param int|null $user_id
   */
  public function buildUserIdFromUrl(?int $user_id) {
    $this->userIdFromRoute = $user_id;
  }

  /**
   * Build the search query.
   *
   * @param string|NULL $search_value
   */
  public function buildSearchQuery(?string $search_value) {
    $spell_check = $this->solrQuery->getSpellcheck();
    $spell_check->setQuery($search_value);

    $search_fields_id = $this->source ? $this->source->getSearchFieldsId() : NULL;

    if (!$search_fields_id) {
      return;
    }

    // The text is one phrase, so that no Solr syntax in it is parsed.
    $search_query_value = $search_value ? $this->solrQuery->getHelper()->escapePhrase($search_value) : '*';

    $query_fields = [];

    foreach ($search_fields_id as $search_field_id) {
      $query_fields[] = "$search_field_id:$search_query_value";
    }

    $this->rawQuery .= empty($this->rawQuery) ?
      '(' . implode(' OR ', $query_fields) . ')' :
      ' AND (' . implode(' OR ', $query_fields) . ')';

    $source_type = get_class($this->source);
    switch ($source_type) {
      case UserGallerySourceType::class:
      case UserInvitesListSourceType::class:
      case UserListSourceType::class:
      case UserRecommendSourceType::class:
      case UserTaggingCommentsSourceType::class:
        // Filter out blocked users.
        $this->rawQuery .= empty($this->rawQuery) ? '(bs_status : true)' : ' AND (bs_status : true)';
        break;
    }
  }

  /**
   * Build the sort and facets to the query.
   *
   * @param array|null $facets_value
   * @param string|null $sort_value
   */
  public function buildSortFacets(?array $facets_value, ?string $sort_value) {
    $this->facets = $this->validateFacetsValue($facets_value ?? []);
    $this->interests = [];
    $sorts = [];

    if (array_key_exists('interests', $this->facets)) {
      $this->interests = $this->facets['interests'];
    }

    // 'any' is the "no sort" option of the client.
    if ($sort_value && $sort_value !== 'any') {
      $sorts = $this->validateSort($sort_value);

      // Check if sort needs a group injection before sending to solr.
      $sort_fields_group_context = [
        DocumentProcessorInterface::SOLR_MOST_ACTIVE_ID_GROUP,
        DocumentProcessorInterface::SOLR_GROUP_ROLES,
      ];

      $sort_field = in_array($sorts[0], $sort_fields_group_context) ?
        $sorts[0] . $this->currentGroup :
        $sorts[0];
      $this->solrQuery->addSort($sort_field, $sorts[1]);
    }

    $sort_options = $sorts ? ($this->source->getAvailableSortOptions()[$sorts[0]] ?? []) : [];
    if (array_key_exists(SourceTypeInterface::SECOND_SORT_KEY, $sort_options)) {
      $second_sorts = $sort_options[SourceTypeInterface::SECOND_SORT_KEY];

      foreach ($second_sorts as $second_sort) {
        $this->solrQuery->addSort($second_sort['id'], $second_sort['direction']);
      }
    }

    $default_sort = $this->source->getSecondDefaultSort();

    // Add second sort.
    if (!empty($default_sort) && 2 === count($default_sort)) {
      $this->solrQuery->addSort($default_sort[0], $default_sort[1]);
    }

    $default_sort = $this->source->getDefaultSort();

    if ($this->currentGroup && DocumentProcessorInterface::SOLR_MOST_ACTIVE_ID === $default_sort[0]) {
      $default_sort[0] = DocumentProcessorInterface::SOLR_MOST_ACTIVE_ID_GROUP . $this->currentGroup;
    }

    // If there are no current sorts check if source has a default sort.
    if (
      !$sort_value &&
      $this->source instanceof SourceTypeInterface &&
      $default_sort = $this->source->getDefaultSort()
    ) {
      $this->solrQuery->addSort($default_sort[0], $default_sort[1]);
    }
    $facets_query = '';

    // Cleaning non solr fields from facets before putting it to the query.
    $this->cleanFacets();
    $exclude_facets = $this->source->getExcludeFacets();
    foreach ($this->facets as $key => $facet_value) {
      // Handle exclude facets with NOT logic.
      if (array_key_exists($key, $exclude_facets)) {
        $filtered = array_filter($facet_value, fn($v) => $v);
        if ($filtered) {
          $real_field = $exclude_facets[$key];
          foreach (array_keys($filtered) as $val) {
            $facets_query .= ' AND -' . $real_field . ':' . $this->solrQuery->getHelper()->escapePhrase((string) $val);
          }
        }
        continue;
      }

      $filtered_value = array_filter($facet_value, function ($value) {
        return $value;
      });

      $values = array_keys($filtered_value);

      if ($filtered_value) {
        // Each value is one phrase, so that no Solr syntax in it is parsed.
        array_walk($values, function (&$value) {
          $value = $this->solrQuery->getHelper()->escapePhrase((string) $value);
        });

        $condition_operator = in_array($key, $this->source->getFacetsUsingOrCondition()) ? 'OR' : 'AND';

        $query_values = count($values) > 1 ?
          '(' . implode(" $condition_operator ", $values) . ')' :
          implode(" $condition_operator ", $values);

        if (in_array($key, DocumentProcessorInterface::SOLR_FIELD_NEED_GROUP_INJECT)) {
          $key = $key . $this->currentGroup;
        }

        $facets_query .= ' AND ' . $key . ':' . $query_values;
      }
    }

    if ($facets_query) {
      $this->rawFieldQuery .= $facets_query;
    }
  }

  /**
   * Build date query.
   *
   * @param string $from_date
   * @param string $end_date
   */
  public function buildDateQuery(string $from_date = '*', string $end_date = '*') {
    $from_date = $this->validateBound('from_date', $from_date, TRUE);
    $end_date = $this->validateBound('end_date', $end_date, TRUE);
    $filter_registration = FALSE;

    if (array_key_exists('filter_registration', $this->facets)) {
      $filter_registration = $this->facets['filter_registration']['open_registration'] ?? FALSE;
    }

    // If source supports date filter and query has a from or to date.
    if ($this->source->supportDateFilter() && ($from_date || $end_date)) {
      $date_fields_id = $this->source->getDateIntervalField();
      $date_from_id = $date_fields_id['from'];
      $date_end_id = $date_fields_id['to'];

      // If user only selected one day, we will only filter on the start date.
      // for eg: user select on widget 23-11-2021 - 23-11-2021 (double click)
      // we only do a query from 23-11-2021 to *.
      $end_date = $end_date === $from_date ? (int) $end_date + 86400 : $end_date;
      $dates_query = [];

      $dates_query[] = "($date_from_id:[$from_date TO $end_date] AND $date_end_id:[$from_date TO $end_date])";
      $dates_query[] = "($date_from_id:[* TO $end_date] AND $date_end_id:[$from_date TO $end_date])";
      $dates_query[] = "($date_from_id:[$from_date TO $end_date] AND $date_end_id:[$end_date TO *])";
      $dates_query[] = "($date_from_id:[* TO $from_date] AND $date_end_id:[$end_date TO *])";
      $date_query = implode(' OR ', $dates_query);

      $date_query = "($date_query)";
      $this->rawQuery .= empty($this->rawQuery) ?
        "$date_query" :
        " AND $date_query";
    }

    if ($this->source->getRegistrationDateIntervalField() && $filter_registration) {
      $date_fields_id = $this->source->getRegistrationDateIntervalField();
      $date_from_id = $date_fields_id['from'];
      $date_end_id = $date_fields_id['to'];
      $now = time();
      $dates_query = [];

      $dates_query[] = "($date_from_id:[* TO $now] AND $date_end_id:[$now TO *])";
      $date_query = implode(' OR ', $dates_query);

      $date_query = "($date_query)";
      $this->rawQuery .= empty($this->rawQuery) ?
        "$date_query" :
        " AND $date_query";
    }
  }

  /**
   * Build numeric range query for range facets.
   *
   * Generic engine driven by the source's getRangeFacets() map. For each
   * configured range facet it adds a Solr range filter of the form
   * "field:[FROM TO TO]" where an unset bound becomes the Solr wildcard "*".
   * The range-facet keys are stripped from the facet.field list elsewhere so
   * Solr does not also build a terms facet on them.
   *
   * @param string $range_from
   *   The lower bound, or "*" when unset.
   * @param string $range_to
   *   The upper bound, or "*" when unset.
   */
  public function buildRangeQuery(string $range_from = '*', string $range_to = '*') {
    $range_from = $this->validateBound('year_from', $range_from, FALSE);
    $range_to = $this->validateBound('year_to', $range_to, FALSE);

    if (!$this->source instanceof SourceTypeInterface) {
      return;
    }

    $range_facets = $this->source->getRangeFacets();
    if (empty($range_facets)) {
      return;
    }

    // Normalise empty bounds to the Solr wildcard.
    $from = ($range_from === '' || $range_from === NULL) ? '*' : $range_from;
    $to = ($range_to === '' || $range_to === NULL) ? '*' : $range_to;

    // Nothing to filter when both bounds are unbounded.
    if ($from === '*' && $to === '*') {
      return;
    }

    foreach ($range_facets as $solr_field) {
      $range_query = "$solr_field:[$from TO $to]";
      $this->rawQuery .= empty($this->rawQuery) ?
        "$range_query" :
        " AND $range_query";
    }
  }

  /**
   * Set all facets to our SOLR request.
   *
   * @param array|null $facets_fields
   */
  public function buildFacets(?array $facets_fields) {
    $facets_fields = $facets_fields ?? [];

    // facet.field takes field names, so that only the declared facets of the
    // source may be asked for.
    $declared = $this->getDeclaredFacetKeys();
    foreach ($facets_fields as $facet) {
      if (!is_string($facet) || !in_array($facet, $declared, TRUE)) {
        throw new InvalidSearchParameterException('facets_options');
      }
    }

    // Remove virtual exclude keys and range-facet keys - Solr should not be
    // asked to build terms facets on them.
    $exclude_keys = $this->source ? array_merge(
      array_keys($this->source->getExcludeFacets()),
      array_keys($this->source->getRangeFacets())
    ) : [];
    $facets_fields = array_filter($facets_fields, fn($f) => !in_array($f, $exclude_keys));

    $facets_fields = array_map(function ($facet) {
      if (!in_array($facet, DocumentProcessorInterface::SOLR_FIELD_NEED_GROUP_INJECT)) {
        return $facet;
      }

      return $facet . $this->currentGroup;
    }, $facets_fields);

    $this->solrQuery->addParam('facet.field', $facets_fields);
  }

  /**
   * Build group query.
   *
   * @param string|NULL $current_group
   */
  public function buildGroupQuery(?string $current_group) {
    if (!$current_group) {
      return;
    }

    // The id is put into field names and queries.
    if (!ctype_digit($current_group)) {
      throw new InvalidSearchParameterException('current_group');
    }

    $this->currentGroup = $current_group;

    if (
      !$this->source->excludingCurrentGroup() &&
      $group_id_fields = $this->source->getPrefilteredGroupFieldId()
    ) {
      $group_query = [];
      foreach ($group_id_fields as $group_id_field) {
        $group_query[] = "$group_id_field:($current_group)";
      }
      $group_query_string = '(' . implode(' OR ', $group_query) . ')';
      $this->rawQuery .= empty($this->rawQuery) ?
        "$group_query_string" :
        " AND $group_query_string";
    }

    if (
      $this->source instanceof SourceTypeInterface &&
      $this->source->excludingCurrentGroup()
    ) {
      $this->generateExcludingUsersGroupQuery($current_group);
    }
  }

  /**
   * Prefilter the query with the topic.
   *
   * @param int|null $topic_term_id
   */
  public function buildPrefilterTopic(?int $topic_term_id) {
    if (!$topic_term_id) {
      return;
    }

    // Handle current term ID sub-query.
    $term_id_fields = $this->source->getPrefilteredTopicsFieldId();
    $term_query = [];
    foreach ($term_id_fields as $term_id_field) {
      $term_query[] = "$term_id_field:($topic_term_id)";
    }
    $term_query_string = '(' . implode(' OR ', $term_query) . ')';
    $this->rawQuery .= empty($this->rawQuery) ?
      "$term_query_string" :
      " AND $term_query_string";
  }

  /**
   * Set the current start and offset.
   *
   * @param int $page
   *   The page, counted from 1.
   * @param int $offset
   *   The number of results per page (or per "load more" batch).
   *
   * @throws \Drupal\eic_search\Exception\InvalidSearchParameterException
   *   When the page is below 1 or the request reaches beyond
   *   self::MAX_RESULT_WINDOW.
   */
  public function buildQueryPager(int $page, int $offset) {
    if ($page < 1) {
      throw new InvalidSearchParameterException('page');
    }
    if ($offset < 0 || $offset > self::MAX_RESULT_WINDOW) {
      throw new InvalidSearchParameterException('offset');
    }
    // Divided, not multiplied: page * offset overflows for a page of PHP_INT_MAX.
    if ($offset > 0 && $page > intdiv(self::MAX_RESULT_WINDOW, $offset)) {
      throw new InvalidSearchParameterException('page');
    }

    $this->solrQuery->setRows($offset);

    //Default value will be to work like pagination.
    if ($this->source instanceof SourceTypeInterface && $this->source->allowPagination()) {
      $this->solrQuery->setStart(($page * $offset) - $offset);
      return;
    }

    //If no pagination, it's a load more so we start at 1.
    $this->solrQuery->setStart(0);
    $this->solrQuery->setRows($offset * $page);
  }

  /**
   * @param bool $system_query
   *   TRUE for an internal query that computes a value for the index, such as
   *   the activity score of a group (ProcessorGroup), which must not depend on
   *   the account that happens to trigger indexing: the restricted-access
   *   filter is not applied. Server-side code only; never derive it from
   *   request input. SolrSearchController never sets it.
   *
   * @return string
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function search(bool $system_query = FALSE) {
    $this->solrQuery->addParam('json.nl', 'arrarr');
    $this->solrQuery->addParam('facet.mincount', 1);
    $this->solrQuery->addParam('facet', 'on');
    $this->solrQuery->addParam('facet.sort', 'false');
    $this->solrQuery->addParam('wt', 'json');

    $this->generatePrefilterEmptyValues();
    $this->generateSpellCheck();
    $this->generateExcludingUser();
    $this->generateQueryInterests();
    $this->generateQueryUserGroupsAndContents();
    $this->generateQueryPrivateContent();
    $this->generateQueryPublishedState();
    $this->generatePrefilterGroupsMembership();
    $this->generatePrefilterByCurrentUser();
    $this->generatePrefilterContentTypes();
    $this->generateExtraPrefilter();
    $this->generateAvoidGroupBookPage();
    $this->generateIgnoreAnonymousNewsStoriesContent();

    $this->solrQuery->addParam('q', !empty($this->rawQuery) ? $this->rawQuery : '*:*');
    $this->solrQuery->addParam('fq', $this->rawFieldQuery);

    $is_admin_group = FALSE;

    if ($this->currentGroup && $current_group_entity = Group::load($this->currentGroup)) {
      $group_owner = EICGroupsHelper::getGroupOwner($current_group_entity);
      $group_admins = EICGroupsHelper::getGroupAdmins($current_group_entity);

      $filtered_admins = array_filter($group_admins, function (GroupMembership $member) {
        return $member->getUser()->id() === $this->currentUser->id();
      });

      $is_admin_group =
        ($group_owner instanceof UserInterface && $group_owner->id() === $this->currentUser->id()) ||
        !empty($filtered_admins);
    }

    if (
      !$is_admin_group &&
      $this->index->isValidProcessor('group_content_access') &&
      GroupAccessContent::supportsIndex($this->index)
    ) {
      $this->index->getProcessor('group_content_access')
        ->preprocessSolrSearchQuery($this->solrQuery);
    }

    if (!$system_query) {
      $this->applyRestrictedContentFilter();
    }

    $response = $this->connector->search($this->solrQuery);
    $body = $response->getBody();

    // Solr answers some errors with the error in the body; the error names the
    // query and the fields of the index, so that it is thrown for the caller
    // to log, never returned as a result.
    $data = json_decode($body, TRUE);
    if ($response->getStatusCode() >= 400 || !is_array($data) || isset($data['error'])) {
      throw new SearchApiException(sprintf('Solr answered the search with HTTP %d: %s', $response->getStatusCode(), $body));
    }

    return $body;
  }

  /**
   * Keeps restricted items out of the search of an untrusted account.
   *
   * This query is raw Solarium, which no Search API processor sees, so that
   * the restricted-access processor is applied here, for every source, group
   * admin or not. Without the processor on the index nothing would filter the
   * search, so that it is refused for an untrusted account.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   When the index lacks the processor and the account is not trusted.
   */
  private function applyRestrictedContentFilter(): void {
    if ($this->index->isValidProcessor('ddc_restricted_access')) {
      /** @var \Drupal\ddc_default_content\Plugin\search_api\processor\RestrictedAccess $processor */
      $processor = $this->index->getProcessor('ddc_restricted_access');
      $processor->preprocessSolrSearchQuery($this->solrQuery);
      return;
    }

    if (!$this->restrictedContentPolicy->isTrusted($this->currentUser)) {
      throw new SearchApiException('The index has no ddc_restricted_access processor, so that a search for an untrusted account is refused.');
    }
  }

  /**
   * Generate query for user interests matching by their topics.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function generateQueryInterests() {
    if (
      empty($this->interests) ||
      !array_key_exists('my_interests', $this->interests) ||
      !$this->interests['my_interests']
    ) {
      return;
    }

    $user_id = $this->currentUser->id();
    $profiles = $this->em
      ->getStorage('profile')
      ->loadByProperties([
        'uid' => $user_id,
        'type' => 'member',
      ]);

    if (empty($profiles)) {
      return;
    }

    $profile = reset($profiles);
    $user_topics = $profile->get(Topics::TERM_TOPICS_ID_FIELD)
      ->referencedEntities();
    $user_topics_id = [0];

    if ($user_topics) {
      $user_topics_id = array_map(function (Term $topic) {
        return $topic->id();
      }, $user_topics);
    }

    $user_topics_string = implode(' OR ', $user_topics_id);
    $this->rawQuery .= " AND (itm_group_field_vocab_topics:($user_topics_string) OR itm_content_field_vocab_topics:($user_topics_string) OR itm_message_node_ref_field_vocab_topics:($user_topics_string))";
  }

  /**
   * Generate query for user's groups and content.
   */
  private function generateQueryUserGroupsAndContents() {
    if (
      empty($this->interests) ||
      !array_key_exists('my_groups', $this->interests) ||
      !$this->interests['my_groups']
    ) {
      return;
    }

    $user_id = $this->currentUser->id();
    $user = User::load($user_id);

    $groups_membership = $this->membershipLoader->loadByUser($user);

    if ($groups_membership) {
      $groups_membership_id = array_map(function (GroupMembership $group_membership) {
        return $group_membership->getGroup()->id();
      }, $groups_membership);
    }

    $groups_membership_string = $groups_membership_id ? implode(' OR ', $groups_membership_id) : -1;
    $group_field_id = $this->source->getPrefilteredGroupFieldId();
    $group_field_id = reset($group_field_id);

    $this->rawFieldQuery .= " AND ($group_field_id:($groups_membership_string) OR its_global_group_parent_id:($groups_membership_string) OR its_content_uid:($groups_membership_string))";
  }

  /**
   * Generate query for private content.
   */
  private function generateQueryPrivateContent() {
    $roles = $this->currentUser->getRoles();

    if (
      in_array(UserHelper::ROLE_TRUSTED_USER, $roles) ||
      UserHelper::isPowerUser($this->currentUser)
    ) {
      return;
    }

    $this->rawFieldQuery .= " AND bs_content_is_private:false";
  }

  /**
   * Add the status query to the query but check for groups if need
   * to show draft/pending for group owner.
   */
  private function generateQueryPublishedState() {
    if (!$this->source instanceof SourceTypeInterface || $this->source->ignorePublishedState()) {
      return;
    }

    $user_id = $this->currentUser->id();
    $is_power_user = UserHelper::isPowerUser($this->currentUser);

    // We need to ignore the publish state for power users or users with
    // permission to view any unpublished content.
    if ($is_power_user || $this->currentUser->hasPermission('view any unpublished content')) {
      return;
    }

    $status_query = ' AND (bs_global_status:true';

    if (
      $this->source->getEntityBundle() === 'group' ||
      $this->source->getEntityBundle() === 'global'
    ) {
      $status_query = ' AND (((bs_global_status:true AND -ss_global_group_last_moderation_state:archived)';
      // User can see their group if status false but he is owner.
      $status_query .= ' OR (its_group_owner_id:' . $user_id . '))';

      $grps = $this->membershipLoader->loadByUser($this->currentUser);

      $grp_ids = array_map(function (GroupMembership $grp_membership) {
        return $grp_membership->getGroup()->id();
      }, $grps);

      $group_filters_id = $this->source->getPrefilteredGroupFieldId();

      // User can see their own archived groups.
      if ($group_filters_id && $grp_ids) {
        $status_query .= ' OR (bs_global_status:true AND ss_global_group_last_moderation_state:archived AND ' . reset($group_filters_id) . ':(' . implode(' OR ', $grp_ids) . '))';
      }
    }

    $query_bundle = [
      "its_group_owner_id:$user_id",
    ];

    switch ($this->source->getEntityBundle()) {
      case 'library':
      case 'discussion':
      case 'node_event':
      case 'news':
      case 'research_institution':
        // Show own content even if it's in draft, archived, ...
        $query_bundle[] = "its_content_uid:$user_id";
        break;

      case 'activity_stream':
        $query_bundle[] = "its_uid:$user_id";
        break;

      case 'global':
        $query_bundle[] = "its_content_uid:$user_id";
        break;
    }

    $status_query .= ' OR (' . implode(' OR ', $query_bundle) . ')';

    $status_query .= ')';

    $this->rawFieldQuery .= $status_query;
  }

  /**
   * Prefilter users by excluding the current group.
   *
   * @param $group_id
   */
  private function generateExcludingUsersGroupQuery($group_id) {
    if (!empty($this->rawFieldQuery)) {
      $this->rawFieldQuery .= " AND !(itm_user__group_content__uid_gid:($group_id))";
      return;
    }

    $this->rawFieldQuery .= "!itm_user__group_content__uid_gid:($group_id)";
  }

  /**
   * Generate spellcheck for solr.
   */
  private function generateSpellcheck() {
    $spell_check = $this->solrQuery->getSpellcheck();
    $spell_check->setDictionary('en');
    $spell_check->setAccuracy(0.5);
    $spell_check->setCollate(TRUE);
    $spell_check->setReload(TRUE);
    $this->solrQuery->setComponent(ComponentAwareQueryInterface::COMPONENT_SPELLCHECK, $spell_check);
  }

  /**
   * Exclude anonymous user and/or current user if configured.
   */
  private function generateExcludingUser() {
    if (
      $this->source instanceof SourceTypeInterface &&
      $this->source->ignoreAnonymousUser()
    ) {
      $this->rawFieldQuery .= ' AND !(' . $this->source->getAuthorFieldId() . ':0)';
    }

    if ($this->source->ignoreContentFromCurrentUser()) {
      $this->rawFieldQuery .= ' AND !(' . $this->source->getAuthorFieldId() . ':' . $this->currentUser->id() . ')';
    }
  }

  /**
   * Get only items from current user.
   */
  private function generatePrefilterByCurrentUser() {
    if (!$this->source->prefilterByCurrentUser()) {
      return;
    }

    $this->rawFieldQuery .= ' AND (' . $this->source->getAuthorFieldId() . ':(' . $this->currentUser->id() . '))';
  }

  /**
   * Filter items by groups where user is member of.
   */
  private function generatePrefilterGroupsMembership() {
    if (!$this->source->prefilterByGroupsMembership()) {
      return;
    }

    $current_user = $this->source->prefilterByUserFromRoute() ?
      User::load($this->userIdFromRoute) :
      $this->currentUser;

    if (!$current_user) {
      return;
    }

    $grps = $this->membershipLoader->loadByUser($current_user);

    $grp_ids = array_map(function (GroupMembership $grp_membership) {
      return $grp_membership->getGroup()->id();
    }, $grps);

    $group_filters_id = $this->source->getPrefilteredGroupFieldId();

    if (!$group_filters_id) {
      return;
    }

    if (empty($grp_ids)) {
      $this->rawFieldQuery .= ' AND (' . reset($group_filters_id) . ':("-1"))';
      return;
    }

    $this->rawFieldQuery .= ' AND (' . reset($group_filters_id) . ':(' . implode(' OR ', $grp_ids) . ' OR "-1"))';
  }

  /**
   * Prefilter values only by certain content types.
   */
  private function generatePrefilterContentTypes() {
    $content_types = $this->source->getPrefilteredContentType();

    if (!$content_types) {
      return;
    }

    $allowed_content_type = implode(' OR ', $content_types);
    $this->rawFieldQuery .= ' AND (' . SourceTypeInterface::SOLR_FIELD_CONTENT_TYPE_ID . ':(' . $allowed_content_type . '))';
  }

  /**
   * Prefilter extra prefilter from source.
   */
  private function generateExtraPrefilter() {
    $extra_filters = $this->source->extraPrefilter();
    $query_extra_filter = [];

    // Add filters by AND operator.
    if (!empty($extra_filters['AND'])) {
      foreach ($extra_filters['AND'] as $field => $values) {
        $query_extra_filter[] = "$field:(" . implode(' AND ', $values) . ")";
      }
    }

    // Add filters by OR operator.
    if (!empty($extra_filters['OR'])) {
      foreach ($extra_filters['OR'] as $field => $values) {
        $query_extra_filter[] = "$field:(" . implode(' OR ', $values) . ")";
      }
    }

    if (empty($query_extra_filter)) {
      return;
    }

    $query_extra_filter = implode(' AND ', $query_extra_filter);
    $this->rawFieldQuery .= " AND ($query_extra_filter)";
  }

  /**
   * Prefilter values where there is no data in it.
   */
  private function generatePrefilterEmptyValues() {
    $fields_filter_empty = $this->source->getFieldsToFilterEmptyValue();

    if (empty($fields_filter_empty)) {
      return;
    }

    foreach ($fields_filter_empty as $field) {
      $this->rawQuery .= empty($this->rawQuery) ?
        "$field:[* TO *]" :
        " AND $field:[* TO *]";
    }
  }

  /**
   * Prefilter book page pre-generated by group creation.
   */
  private function generateAvoidGroupBookPage() {
    // Add filter if it's a book page not in a group. To avoid pre-generated book page.
    // Solr cannot handle negate OR in parentheses, so we need to do the reverse condition by negate it.
    $query = '-(!its_global_group_parent_id:("-1") OR (ss_global_content_type:book))';

    $this->rawFieldQuery .= empty($this->rawFieldQuery) ?
      "$query" :
      " AND $query";
  }

  /**
   * Prefilter news/story from content where user is anonymous and content coming from Organisation.
   */
  private function generateIgnoreAnonymousNewsStoriesContent() {
    if (!$this->source instanceof NewsStorySourceType || !$this->currentUser->isAnonymous()) {
      return;
    }

    // Do not include organisation news as anonymous.
    $query = '(ss_global_group_parent_type:("" OR event OR group))';

    $this->rawFieldQuery .= empty($this->rawFieldQuery) ?
      "$query" :
      " AND $query";
  }

  /**
   * Returns the facet keys that the current source accepts.
   *
   * These are its declared facets, exclude facets and range facets.
   */
  private function getDeclaredFacetKeys(): array {
    if (!$this->source instanceof SourceTypeInterface) {
      return [];
    }

    return array_map('strval', array_merge(
      array_keys($this->source->getAvailableFacets()),
      array_keys($this->source->getExcludeFacets()),
      array_keys($this->source->getRangeFacets())
    ));
  }

  /**
   * Checks the facet values of a request.
   *
   * The keys are concatenated into the fq as field names, so that only the
   * declared facets of the source are accepted, plus the keys of the filter
   * links of the site (SearchHelper::BEAUTIFIED_QUERY_PARAMS) and the special
   * keys 'interests' and 'filter_registration' that the client sends and the
   * search reads as flags. Each value must be a list of value => selected.
   *
   * @throws \Drupal\eic_search\Exception\InvalidSearchParameterException
   */
  private function validateFacetsValue(array $facets_value): array {
    $accepted = array_merge(
      $this->getDeclaredFacetKeys(),
      array_keys(SearchHelper::BEAUTIFIED_QUERY_PARAMS),
      ['interests', 'filter_registration']
    );

    foreach ($facets_value as $key => $values) {
      if (!in_array((string) $key, $accepted, TRUE) || !is_array($values)) {
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

  /**
   * Checks a sort of a request and returns it as [field, direction].
   *
   * The field is put into the sort parameter, so that only the declared sort
   * fields of the source and its default sort field are accepted, with an
   * ASC or DESC direction.
   *
   * @throws \Drupal\eic_search\Exception\InvalidSearchParameterException
   */
  private function validateSort(string $sort_value): array {
    $sorts = explode('__', $sort_value);
    if (count($sorts) !== 2 || !in_array(strtoupper($sorts[1]), ['ASC', 'DESC'], TRUE)) {
      throw new InvalidSearchParameterException('sort_value');
    }

    $fields = array_map('strval', array_keys($this->source->getAvailableSortOptions()));
    $default_sort = $this->source->getDefaultSort();
    if (!empty($default_sort[0])) {
      $fields[] = $default_sort[0];
      // Inside a group the client sends the group variant of the default
      // "most active" sort (SearchOverviewBlock).
      if ($default_sort[0] === DocumentProcessorInterface::SOLR_MOST_ACTIVE_ID) {
        $fields[] = DocumentProcessorInterface::SOLR_MOST_ACTIVE_ID_GROUP;
      }
    }
    if (!in_array($sorts[0], $fields, TRUE)) {
      throw new InvalidSearchParameterException('sort_value');
    }

    return [$sorts[0], strtoupper($sorts[1])];
  }

  /**
   * Checks a bound of a date or numeric range of a request.
   *
   * A bound is '*' (unbounded), empty (not set) or a number of seconds or
   * years; a timestamp with a fraction, as the client may send, is truncated.
   *
   * @throws \Drupal\eic_search\Exception\InvalidSearchParameterException
   */
  private function validateBound(string $parameter, string $value, bool $allow_fraction): string {
    if ($value === '*' || $value === '' || ctype_digit($value)) {
      return $value;
    }
    if ($allow_fraction && preg_match('/^\d+\.\d+$/', $value)) {
      return (string) (int) $value;
    }

    throw new InvalidSearchParameterException($parameter);
  }

  /**
   * Clean useless variables for solr before sending it.
   */
  private function cleanFacets() {
    unset($this->facets['interests']);
    unset($this->facets['filter_registration']);
  }

}
