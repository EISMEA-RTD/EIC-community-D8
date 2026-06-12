<?php

namespace Drupal\eic_search\Search\Sources;

/**
 * Resource source type for the Resources Library search/filter interface.
 *
 * Defines search configuration for "resource" nodes including facets, sort
 * options, Solr field mappings and the publication year range facet.
 *
 * @package Drupal\eic_search\Search\Sources
 */
final class ResourceSourceType extends SourceType {

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return $this->t('Resources', [], ['context' => 'eic_search']);
  }

  /**
   * {@inheritdoc}
   */
  public function getSourcesId(): array {
    return ['node'];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityBundle(): string {
    return 'resource';
  }

  /**
   * {@inheritdoc}
   */
  public function getLayoutTheme(): string {
    return 'resources-overview';
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableFacets(): array {
    return [
      'sm_resource_format' => $this->t('Format', [], ['context' => 'eic_search']),
      'sm_resource_language' => $this->t('Language', [], ['context' => 'eic_search']),
      'sm_resource_thematic' => $this->t('Thematic area', [], ['context' => 'eic_search']),
      'sm_resource_geo_scope' => $this->t('Geographic scope', [], ['context' => 'eic_search']),
      'sm_resource_source' => $this->t('Source', [], ['context' => 'eic_search']),
      'sm_resource_confidentiality' => $this->t('Confidentiality', [], ['context' => 'eic_search']),
      'ss_resource_type' => $this->t('Type', [], ['context' => 'eic_search']),
      'its_resource_pub_year' => $this->t('Publication year', [], ['context' => 'eic_search']),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableSortOptions(): array {
    return [
      'score' => [
        'label' => $this->t('Relevance', [], ['context' => 'eic_search']),
        'DESC' => $this->t('Relevance', [], ['context' => 'eic_search']),
      ],
      'ss_global_title' => [
        'label' => $this->t('Title', [], ['context' => 'eic_search']),
        'ASC' => $this->t('Title (A-Z)', [], ['context' => 'eic_search']),
        'DESC' => $this->t('Title (Z-A)', [], ['context' => 'eic_search']),
      ],
      'ss_drupal_changed_timestamp' => [
        'label' => $this->t('Last Updated', [], ['context' => 'eic_search']),
        'DESC' => $this->t('Recently Updated', [], ['context' => 'eic_search']),
        'ASC' => $this->t('Oldest Updated', [], ['context' => 'eic_search']),
      ],
      'its_resource_pub_year' => [
        'label' => $this->t('Publication year', [], ['context' => 'eic_search']),
        'DESC' => $this->t('Publication year (newest)', [], ['context' => 'eic_search']),
        'ASC' => $this->t('Publication year (oldest)', [], ['context' => 'eic_search']),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultSort(): array {
    return ['score', 'DESC'];
  }

  /**
   * {@inheritdoc}
   */
  public function getSecondDefaultSort(): array {
    return ['ss_global_title', 'ASC'];
  }

  /**
   * {@inheritdoc}
   */
  public function getSearchFieldsId(): array {
    return [
      'tm_global_title',
      'tm_X3b_en_rendered_item',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function allowPagination(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getLoadMoreBatchItems(): int {
    return 20;
  }

  /**
   * {@inheritdoc}
   */
  public function getUniqueId(): string {
    return 'resource-' . parent::getUniqueId();
  }

  /**
   * {@inheritdoc}
   */
  public function getPrefilteredContentType(): array {
    return ['resource'];
  }

  /**
   * {@inheritdoc}
   */
  public function getRangeFacets(): array {
    return [
      'its_resource_pub_year' => 'its_resource_pub_year',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function requiresAuthentication(): bool {
    return TRUE;
  }

}
