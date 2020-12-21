<?php

namespace Drupal\odoo_sync\Traits;

use Drupal\Core\Entity\EntityInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\link\LinkItemInterface;

/**
 * Trait FieldCreationTrait helps toc create Fields.
 *
 * @package Drupal\base_master_global\Tests\Functional
 */
trait FieldCreationTrait {

  /**
   * Create New Storage Field.
   *
   * @param string $fieldName
   *   Name.
   * @param string $type
   *   Field Type.
   * @param string $entity
   *   Entity type.
   *
   * @return \Drupal\Core\Entity\EntityBase|\Drupal\Core\Entity\EntityInterface
   *   Get the Field.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createFieldStorage(string $fieldName, string $type, $entity = 'node') {
    $fieldStorage = FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entity,
      'type' => $type,
    ]);
    $fieldStorage->save();

    return $fieldStorage;
  }

  /**
   * Create Link Field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $fieldStorage
   *   Entity Storage.
   * @param string $bundle
   *   Type content name.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createLinkField(EntityInterface $fieldStorage, string $bundle) {
    $field = FieldConfig::create([
      'field_storage' => $fieldStorage,
      'bundle' => $bundle,
      'settings' => [
        'title' => DRUPAL_DISABLED,
        'link_type' => LinkItemInterface::LINK_GENERIC,
      ],
    ]);
    $field->save();
  }

  /**
   * Create Simple field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $fieldStorage
   *   Storage field.
   * @param string $bundle
   *   Node name (bundle).
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function createField(EntityInterface $fieldStorage, string $bundle) {
    $field = FieldConfig::create([
      'field_storage' => $fieldStorage,
      'bundle' => $bundle,
      'settings' => [
        'title' => DRUPAL_DISABLED,
      ],
    ]);
    $field->save();
  }

  /**
   * Add field function.
   *
   * @param string $name
   *   Name.
   * @param string $type
   *   Type.
   * @param string $bundle
   *   Bundle.
   * @param string $entity
   *   Entity.
   * @param array $storage_settings
   *   Storage settings.
   * @param array $field_settings
   *   Field settings.
   *
   * @return \Drupal\Core\Entity\EntityBase|\Drupal\Core\Entity\EntityInterface
   *   Entity reference.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function addField($name, $label, $description, $type, $bundle, $entity = 'node', array $storage_settings = [], array $field_settings = []) {
    $field_config = FieldStorageConfig::loadByName($entity, $name);
    if ($field_config === NULL) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entity,
        'type' => $type,
        'settings' => $storage_settings,
        'cardinality' => !empty($storage_settings['cardinality']) ? $storage_settings['cardinality'] : 1,
      ])->save();
      $field_config = FieldConfig::create([
        'field_name' => $name,
        'label' => $label,
        'entity_type' => $entity,
        'bundle' => $bundle,
        'required' => !empty($field_settings['required']),
        'settings' => $field_settings,
        'description' => $description,
      ]);
      $field_config->save();
      entity_get_form_display('node', $bundle, 'default')
        ->setComponent($name)
        ->save();
    }else{
      return $field_config;
    }
    return $field_config;
  }
  /**
   * Create Taxonomy field.
   *
   * @param mixed $fieldStorage
   *   Storage field.
   * @param mixed $bundle
   *   Name.
   * @param mixed $label
   *   Label name.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createTaxonomyField($fieldStorage, $bundle, $label = 'label') {
    FieldConfig::create([
      'field_storage' => $fieldStorage,
      'label' => $label,
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * Create a new image field.
   *
   * @param string $name
   *   The name of the new field (all lowercase), exclude the "field_" prefix.
   * @param string $type_name
   *   The node type that this field will be added to.
   * @param mixed $entity
   *   Entity type.
   * @param array $storage_settings
   *   (optional) A list of field storage settings that will be added to the
   *   defaults.
   * @param array $field_settings
   *   (optional) A list of instance settings that will be added to the instance
   *   defaults.
   * @param array $widget_settings
   *   (optional) Widget settings to be added to the widget defaults.
   * @param array $formatter_settings
   *   (optional) Formatter settings to be added to the formatter defaults.
   * @param string $description
   *   (optional) A description for the field. Defaults to ''.
   *
   * @return \Drupal\Core\Entity\EntityBase|\Drupal\Core\Entity\EntityInterface
   *   Return Entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createImageField($name, $type_name, $entity, array $storage_settings = [], array $field_settings = [], array $widget_settings = [], array $formatter_settings = [], $description = '') {

    $field_storage = FieldStorageConfig::loadByName($entity, $name);

    if (!$field_storage) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entity,
        'type' => 'image',
        'settings' => $storage_settings,
        'cardinality' => !empty($storage_settings['cardinality']) ? $storage_settings['cardinality'] : 1,
      ])->save();
    }

    $field_config = FieldConfig::create([
      'field_name' => $name,
      'label' => $name,
      'entity_type' => $entity,
      'bundle' => $type_name,
      'required' => !empty($field_settings['required']),
      'settings' => $field_settings,
      'description' => $description,
    ]);
    $field_config->save();

    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');
    $display_repository->getFormDisplay($entity, $type_name)
      ->setComponent($name, [
        'type' => 'image_image',
        'settings' => $widget_settings,
      ])
      ->save();

    $display_repository->getViewDisplay($entity, $type_name)
      ->setComponent($name, [
        'type' => 'image',
        'settings' => $formatter_settings,
      ])
      ->save();

    return $field_config;
  }

}
