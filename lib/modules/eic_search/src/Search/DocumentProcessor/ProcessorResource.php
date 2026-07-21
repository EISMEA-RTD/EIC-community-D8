<?php

namespace Drupal\eic_search\Search\DocumentProcessor;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Solarium\QueryType\Update\Query\Document;

/**
 * Document processor for "resource" nodes.
 *
 * Maps taxonomy term reference fields to Solr facet fields, resolves the
 * attached media file to a direct download URL with metadata, and exposes
 * the publication year as an integer range field for the Resources Library
 * search interface.
 *
 * @package Drupal\eic_search\Search\DocumentProcessor
 */
class ProcessorResource extends DocumentProcessor {

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  private $urlGenerator;

  /**
   * Constructs a new ProcessorResource instance.
   *
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $urlGenerator
   *   The file URL generator.
   */
  public function __construct(FileUrlGeneratorInterface $urlGenerator) {
    $this->urlGenerator = $urlGenerator;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(array $fields): bool {
    return ($fields['ss_search_api_datasource'] ?? '') === 'entity:node'
      && ($fields['ss_content_type'] ?? '') === 'resource';
  }

  /**
   * {@inheritdoc}
   */
  public function process(Document &$document, array $fields, array $items = []): void {
    $nid = $fields['its_content_nid'] ?? NULL;
    if (!$nid) {
      return;
    }

    $node = Node::load($nid);
    if (!$node instanceof NodeInterface) {
      return;
    }

    // Map taxonomy term names to Solr facet fields.
    $this->mapTaxonomyField($document, $node, 'field_resource_format', 'ss_resource_format', 'sm_resource_format');
    $this->mapTaxonomyField($document, $node, 'field_resource_language', 'ss_resource_language', 'sm_resource_language');
    $this->mapTaxonomyField($document, $node, 'field_resource_thematic', 'ss_resource_thematic', 'sm_resource_thematic');
    $this->mapTaxonomyField($document, $node, 'field_resource_geo_scope', 'ss_resource_geo_scope', 'sm_resource_geo_scope');
    $this->mapTaxonomyField($document, $node, 'field_resource_source', 'ss_resource_source', 'sm_resource_source');
    $this->mapTaxonomyField($document, $node, 'field_resource_confidentiality', 'ss_resource_confidentiality', 'sm_resource_confidentiality');
    $this->mapTaxonomyField($document, $node, 'field_resource_type', 'ss_resource_type', 'sm_resource_type');

    // Publication year as an integer range field.
    $this->mapPublicationYear($document, $node);

    // Plain-text excerpt of the description.
    $this->mapDescription($document, $node);

    // Resolve the attached media file to a direct download URL + metadata.
    $this->mapResourceFile($document, $node);
  }

  /**
   * Maps a taxonomy reference field to Solr document fields.
   *
   * @param \Solarium\QueryType\Update\Query\Document $document
   *   The Solr document being processed.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $drupal_field
   *   The Drupal field machine name.
   * @param string $solr_single
   *   The Solr field name for single value (ss_ prefix).
   * @param string $solr_multi
   *   The Solr field name for multi value (sm_ prefix).
   */
  protected function mapTaxonomyField(
    Document &$document,
    FieldableEntityInterface $entity,
    string $drupal_field,
    string $solr_single,
    string $solr_multi
  ): void {
    if (!$entity->hasField($drupal_field)) {
      return;
    }

    $field = $entity->get($drupal_field);
    if ($field->isEmpty()) {
      return;
    }

    $terms = $field->referencedEntities();
    $names = array_map(fn($term) => $term->label(), $terms);

    if (!empty($names)) {
      $document->setField($solr_single, reset($names));
      $document->setField($solr_multi, $names);
    }
  }

  /**
   * Maps the publication year to an integer Solr range field.
   *
   * @param \Solarium\QueryType\Update\Query\Document $document
   *   The Solr document being processed.
   * @param \Drupal\node\NodeInterface $node
   *   The resource node.
   */
  protected function mapPublicationYear(Document &$document, NodeInterface $node): void {
    if (!$node->hasField('field_resource_pub_year')) {
      return;
    }

    $field = $node->get('field_resource_pub_year');
    if ($field->isEmpty()) {
      return;
    }

    $document->setField('its_resource_pub_year', (int) $field->value);
  }

  /**
   * Maps the plain-text description to a Solr field.
   *
   * @param \Solarium\QueryType\Update\Query\Document $document
   *   The Solr document being processed.
   * @param \Drupal\node\NodeInterface $node
   *   The resource node.
   */
  protected function mapDescription(Document &$document, NodeInterface $node): void {
    if (!$node->hasField('field_resource_description')) {
      return;
    }

    $field = $node->get('field_resource_description');
    if ($field->isEmpty()) {
      return;
    }

    $value = (string) $field->value;
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($value)));
    if ($plain === '') {
      return;
    }

    $document->setField('ss_resource_description', $plain);
  }

  /**
   * Resolves the attached media file and maps its metadata to Solr fields.
   *
   * Walks field_resource_file (media) -> field_media_file (file) and sets the
   * direct, absolute file URL plus human-formatted size, uppercase extension
   * and mime type.
   *
   * @param \Solarium\QueryType\Update\Query\Document $document
   *   The Solr document being processed.
   * @param \Drupal\node\NodeInterface $node
   *   The resource node.
   */
  protected function mapResourceFile(Document &$document, NodeInterface $node): void {
    if (!$node->hasField('field_resource_file') || $node->get('field_resource_file')->isEmpty()) {
      return;
    }

    $media = $node->get('field_resource_file')->entity;
    if (!$media instanceof MediaInterface) {
      return;
    }

    if (!$media->hasField('field_media_file') || $media->get('field_media_file')->isEmpty()) {
      return;
    }

    $file = $media->get('field_media_file')->entity;
    if (!$file instanceof FileInterface) {
      return;
    }

    // Direct, absolute file URL so the teaser title links straight to the file.
    $document->setField('ss_url', $this->urlGenerator->generateAbsoluteString($file->getFileUri()));
    $document->setField('ss_resource_file_size', (string) ByteSizeMarkup::create((int) $file->getSize()));
    $document->setField('ss_resource_file_ext', strtoupper(pathinfo($file->getFilename(), PATHINFO_EXTENSION)));
    $document->setField('ss_resource_file_mime', $file->getMimeType());
  }

}
