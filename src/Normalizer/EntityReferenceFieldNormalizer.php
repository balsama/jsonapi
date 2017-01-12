<?php

namespace Drupal\jsonapi\Normalizer;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinition;
use Drupal\jsonapi\ResourceType\ResourceTypeRepository;
use Drupal\jsonapi\Resource\EntityCollection;
use Drupal\jsonapi\Error\SerializableHttpException;
use Drupal\jsonapi\LinkManager\LinkManager;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Class EntityReferenceFieldNormalizer.
 *
 * @package Drupal\jsonapi\Normalizer
 */
class EntityReferenceFieldNormalizer extends FieldNormalizer implements DenormalizerInterface {

  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = EntityReferenceFieldItemListInterface::class;

  /**
   * The link manager.
   *
   * @param \Drupal\jsonapi\LinkManager\LinkManager
   */
  protected $linkManager;

  /**
   * The entity field manager.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;

  /**
   * The field plugin manager.
   *
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface
   */
  protected $pluginManager;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Instantiates a EntityReferenceFieldNormalizer object.
   *
   * @param \Drupal\jsonapi\LinkManager\LinkManager $link_manager
   *   The link manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $plugin_manager
   *   The plugin manager for fields.
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeRepository $resource_type_repository
   *   The JSON API resource type repository.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(LinkManager $link_manager, EntityFieldManagerInterface $field_manager, FieldTypePluginManagerInterface $plugin_manager, ResourceTypeRepository $resource_type_repository, EntityRepositoryInterface $entity_repository) {
    $this->linkManager = $link_manager;
    $this->fieldManager = $field_manager;
    $this->pluginManager = $plugin_manager;
    $this->resourceTypeRepository = $resource_type_repository;
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($field, $format = NULL, array $context = array()) {
    /* @var $field \Drupal\Core\Field\FieldItemListInterface */
    // Build the relationship object based on the Entity Reference and normalize
    // that object instead.
    $main_property = $field->getItemDefinition()->getMainPropertyName();
    $definition = $field->getFieldDefinition();
    $cardinality = $definition
      ->getFieldStorageDefinition()
      ->getCardinality();
    $entity_collection = new EntityCollection(array_map(function ($item) {
      return $item->get('entity')->getValue();
    }, (array) $field->getIterator()));
    $relationship = new Relationship($this->resourceTypeRepository, $field->getName(), $cardinality, $entity_collection, $field->getEntity(), $main_property);
    return $this->serializer->normalize($relationship, $format, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = array()) {
    // If we get to here is through a write method on a relationship operation.
    /** @var \Drupal\jsonapi\ResourceType\ResourceType $resource_type */
    $resource_type = $context['resource_type'];
    $entity_type_id = $resource_type->getEntityTypeId();
    $field_definitions = $this->fieldManager->getFieldDefinitions(
      $entity_type_id,
      $resource_type->getBundle()
    );
    if (empty($context['related']) || empty($field_definitions[$context['related']])) {
      throw new SerializableHttpException(400, 'Invalid or missing related field.');
    }
    /* @var \Drupal\field\Entity\FieldConfig $field_definition */
    $field_definition = $field_definitions[$context['related']];
    // This is typically 'target_id'.
    $item_definition = $field_definition->getItemDefinition();
    $property_key = $item_definition->getMainPropertyName();
    $target_resources = $this->getAllowedResourceTypes($item_definition);

    $is_multiple = $field_definition->getFieldStorageDefinition()->isMultiple();
    $data = $this->massageRelationshipInput($data, $is_multiple);
    $values = array_map(function ($value) use ($property_key, $target_resources) {
      // Make sure that the provided type is compatible with the targeted
      // resource.
      if (!in_array($value['type'], $target_resources)) {
        throw new SerializableHttpException(400, sprintf(
          'The provided type (%s) does not mach the destination resource types (%s).',
          $value['type'],
          implode(', ', $target_resources)
        ));
      }

      // Load the entity by UUID.
      list($entity_type_id,) = explode('--', $value['type']);
      $entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $value['id']);
      $value['id'] = $entity ? $entity->id() : NULL;

      return [$property_key => $value['id']];
    }, $data['data']);
    return $this->pluginManager
      ->createFieldItemList($context['target_entity'], $context['related'], $values);
  }

  /**
   * Validates and massages the relationship input depending on the cardinality.
   *
   * @param array $data
   *   The input data from the body.
   * @param bool $is_multiple
   *   Indicates if the relationship is to-many.
   *
   * @return array
   *   The massaged data array.
   */
  protected function massageRelationshipInput($data, $is_multiple) {
    if ($is_multiple) {
      if (!is_array($data['data'])) {
        throw new SerializableHttpException(400, 'Invalid body payload for the relationship.');
      }
      // Leave the invalid elements.
      $invalid_elements = array_filter($data['data'], function ($element) {
        return empty($element['type']) || empty($element['id']);
      });
      if ($invalid_elements) {
        throw new SerializableHttpException(400, 'Invalid body payload for the relationship.');
      }
    }
    else {
      // For to-one relationships you can have a NULL value.
      if (is_null($data['data'])) {
        return ['data' => []];
      }
      if (empty($data['data']['type']) || empty($data['data']['id'])) {
        throw new SerializableHttpException(400, 'Invalid body payload for the relationship.');
      }
      $data['data'] = [$data['data']];
    }
    return $data;
  }

  /**
   * Build the list of resource types supported by this entity reference field.
   *
   * @param \Drupal\Core\Field\TypedData\FieldItemDataDefinition $item_definition
   *   The field item definition.
   *
   * @return string[]
   *   List of resource types.
   */
  protected function getAllowedResourceTypes(FieldItemDataDefinition $item_definition) {
    // Build the list of allowed resources.
    $target_entity_id = $item_definition->getSetting('target_type');
    $handler_settings = $item_definition->getSetting('handler_settings');
    $target_bundles = empty($handler_settings['target_bundles']) ?
      [] :
      $handler_settings['target_bundles'];
    return array_map(function ($target_bundle) use ($target_entity_id) {
      return $this->resourceTypeRepository
        ->get($target_entity_id, $target_bundle)
        ->getTypeName();
    }, $target_bundles);
  }

}
