<?php

/**
 * @file
 * Hooks provided by the EIC Search module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the Solr document of a research institution before it is indexed.
 *
 * Called from ProcessorResearchInstitution::process(), after the taxonomy
 * facet fields have been set. Use it to add facet fields whose value is
 * computed by another module, so eic_search does not have to depend on it.
 *
 * @param \Solarium\QueryType\Update\Query\Document $document
 *   The Solr document being built. Set fields with setField().
 * @param \Drupal\group\Entity\GroupInterface $group
 *   The research_institution group being indexed.
 *
 * @see \Drupal\eic_search\Search\DocumentProcessor\ProcessorResearchInstitution::process()
 */
function hook_eic_search_research_institution_document_alter(\Solarium\QueryType\Update\Query\Document $document, \Drupal\group\Entity\GroupInterface $group): void {
  $document->setField('ss_example', 'value');
}

/**
 * @} End of "addtogroup hooks".
 */
