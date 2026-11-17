<?php

namespace Drupal\eic_groups\Drush\Commands;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\eic_groups\Constants\NodeProperty;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Class CreateFieldEditAccess
 *
 * @package Drupal\eic_groups\Routing
 */
class CreateFieldEditAccessCommands extends DrushCommands {

  /**
   * Drush command that displays the given text.
   *
   */
  #[CLI\Command(name: 'eg:create-field-access', aliases: ['eg:cfa'])]
  #[CLI\Usage(name: 'eg:create-field-access', description: 'Creates the "Editable by members" node base field.')]
  public function createField() {
    // Creates new node base field.
    $field_storage_definition = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Editable by members'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setDescription(t('When checked, group members are able to edit the content of this page.'))
      ->setDisplayOptions('view', ['weight' => 1])
      ->setDisplayOptions('form', ['weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    \Drupal::entityDefinitionUpdateManager()
      ->installFieldStorageDefinition(NodeProperty::MEMBER_CONTENT_EDIT_ACCESS, 'node', 'node', $field_storage_definition);

    $this->output()->writeln("Field correctly created");
  }

}
