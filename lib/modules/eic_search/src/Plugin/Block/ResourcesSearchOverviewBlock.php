<?php

namespace Drupal\eic_search\Plugin\Block;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\eic_search\Search\Sources\ResourceSourceType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Resources Search Overview block.
 *
 * This block renders the React-based search interface for the Resources
 * Library with faceted search, a publication year range facet, sorting and
 * pagination capabilities.
 *
 * @Block(
 *   id = "resources_search_overview",
 *   admin_label = @Translation("Resources Search Overview"),
 *   category = @Translation("DDC"),
 * )
 */
final class ResourcesSearchOverviewBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The lower bound of the publication year range facet.
   */
  private const YEAR_RANGE_MIN = 2019;

  /**
   * Constructs a new ResourcesSearchOverviewBlock instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\eic_search\Search\Sources\ResourceSourceType $sourceType
   *   The resource source type service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The datetime.time service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ResourceSourceType $sourceType,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('eic_search.source_type.resource'),
      $container->get('request_stack'),
      $container->get('current_user'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $facets = $this->sourceType->getAvailableFacets();
    $sorts = $this->sourceType->getAvailableSortOptions();
    $default_sort = $this->sourceType->getDefaultSort();

    // Get current search query from URL.
    $search_value = $this->requestStack
      ->getCurrentRequest()
      ->query
      ->get('search', '');

    // Extract filter parameters from URL.
    $prefilters = $this->extractFilterFromUrl();

    // Build translations array for frontend.
    $translations = $this->buildTranslations();

    // Build API endpoint URL.
    $api_url = Url::fromRoute('eic_search.solr_search')->toString();

    // Facet field => widget type map for the React app.
    $facet_widgets = $this->buildFacetWidgets();

    // Publication year range bounds.
    $year_range = [
      'min' => self::YEAR_RANGE_MIN,
      'max' => (int) date('Y', $this->time->getRequestTime()),
    ];

    // Prepare settings for drupalSettings.
    $settings = [
      'sourceBundle' => $this->sourceType->getEntityBundle(),
      'datasource' => $this->sourceType->getSourcesId(),
      'defaultSort' => $default_sort,
      'allowPagination' => $this->sourceType->allowPagination(),
      'loadMoreNumber' => $this->sourceType->getLoadMoreBatchItems(),
      'pageOptions' => [10, 20, 50, 100],
      'enableSearch' => TRUE,
      'layoutTheme' => $this->sourceType->getLayoutTheme(),
    ];

    // Cache metadata.
    $cache = [
      'contexts' => [
        'url.path',
        'url.query_args',
        'user.roles',
      ],
      'tags' => [
        'config:search_api.index.global',
      ],
    ];

    // Filter virtual exclude keys and range-facet keys from facet.field list.
    $exclude_keys = array_merge(
      array_keys($this->sourceType->getExcludeFacets()),
      array_keys($this->sourceType->getRangeFacets())
    );
    $solr_facet_fields = array_filter(
      array_keys($facets),
      fn($key) => !in_array($key, $exclude_keys)
    );

    return [
      '#theme' => 'resources_search_overview_block',
      '#facets' => array_values($solr_facet_fields),
      '#sorts' => array_keys($sorts),
      '#translations' => $translations,
      '#settings' => $settings,
      '#url' => $api_url,
      '#search_string' => $search_value,
      '#prefilters' => $prefilters,
      '#isAnonymous' => $this->currentUser->isAnonymous(),
      '#source_class' => ResourceSourceType::class,
      '#cache' => $cache,
      '#attached' => [
        'library' => [
          'ddc_theme/react-block-overview-search',
        ],
        'drupalSettings' => [
          'overview' => [
            'default_sorting_option' => $default_sort,
            'source_bundle_id' => $this->sourceType->getEntityBundle(),
            'is_group_owner' => FALSE,
            'is_group_admin' => FALSE,
            'is_power_user' => FALSE,
          ],
          'resourcesSearch' => [
            'apiUrl' => $api_url,
            'facets' => $facets,
            'sorts' => $sorts,
            'settings' => $settings,
            'translations' => $translations,
            'facetWidgets' => $facet_widgets,
            'yearRange' => $year_range,
            'currentUser' => [
              'isAnonymous' => $this->currentUser->isAnonymous(),
              'roles' => $this->currentUser->getRoles(),
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Builds the facet field => widget type map for the React frontend.
   *
   * @return array
   *   Associative array of Solr facet field => widget type.
   */
  private function buildFacetWidgets(): array {
    return [
      'sm_resource_format' => 'multi',
      'sm_resource_language' => 'multi',
      'sm_resource_thematic' => 'multi',
      'sm_resource_geo_scope' => 'multi',
      'sm_resource_source' => 'multi',
      'sm_resource_confidentiality' => 'multi',
      'ss_resource_type' => 'single',
      'its_resource_pub_year' => 'range',
    ];
  }

  /**
   * Builds translations array for React frontend.
   *
   * @return array
   *   Array of translated strings keyed by translation key.
   */
  private function buildTranslations(): array {
    return [
      'filter' => $this->t('Filter', [], ['context' => 'eic_search']),
      'refine' => $this->t('Refine your search', [], ['context' => 'eic_search']),
      'search_placeholder' => $this->t('Search resources', [], ['context' => 'eic_search']),
      'search_text' => $this->t('Search for resources', [], ['context' => 'eic_search']),
      'no_results_title' => $this->t('No resources found', [], ['context' => 'eic_search']),
      'no_results_body' => $this->t('Please try again with different filters or keywords', [], ['context' => 'eic_search']),
      'clear_all' => $this->t('Clear all', [], ['context' => 'eic_search']),
      'active_filter' => $this->t('Active filter', [], ['context' => 'eic_search']),
      'sort_by' => $this->t('Sort by', [], ['context' => 'eic_search']),
      'showing' => $this->t('Showing', [], ['context' => 'eic_search']),
      'sort_any' => $this->t('- Any -', [], ['context' => 'eic_search']),
      'load_more' => $this->t('Load more', [], ['context' => 'eic_search']),
      'results_per_page' => $this->t('Results per page', [], ['context' => 'eic_search']),
      // Facet translations.
      'sm_resource_format' => $this->t('Format', [], ['context' => 'eic_search']),
      'sm_resource_language' => $this->t('Language', [], ['context' => 'eic_search']),
      'sm_resource_thematic' => $this->t('Thematic area', [], ['context' => 'eic_search']),
      'sm_resource_geo_scope' => $this->t('Geographic scope', [], ['context' => 'eic_search']),
      'sm_resource_source' => $this->t('Source', [], ['context' => 'eic_search']),
      'sm_resource_confidentiality' => $this->t('Confidentiality', [], ['context' => 'eic_search']),
      'ss_resource_type' => $this->t('Type', [], ['context' => 'eic_search']),
      'its_resource_pub_year' => $this->t('Publication year', [], ['context' => 'eic_search']),
      // Year range widget.
      'year_from' => $this->t('From', [], ['context' => 'eic_search']),
      'year_to' => $this->t('To', [], ['context' => 'eic_search']),
    ];
  }

  /**
   * Extracts filter values from URL query parameters.
   *
   * Example URL format: ?filter[format][0]=PDF&filter[language][0]=English
   *
   * @return array|null
   *   Array of filter values keyed by facet name, or NULL if no filters.
   */
  private function extractFilterFromUrl(): ?array {
    $filters = $this->requestStack->getCurrentRequest()
      ->query
      ->all('filter');

    if (!is_array($filters)) {
      return NULL;
    }

    // Transform filter structure for frontend consumption.
    $processed_filters = [];
    foreach ($filters as $facet => $values) {
      if (is_array($values)) {
        $processed_filters[$facet] = array_values($values);
      }
    }

    return $processed_filters ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), [
      'url.query_args',
      'user.roles',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return Cache::mergeTags(parent::getCacheTags(), [
      'config:search_api.index.global',
      'node_list:resource',
    ]);
  }

}
