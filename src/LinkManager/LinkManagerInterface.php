<?php

namespace Drupal\jsonapi\LinkManager;

use Drupal\jsonapi\ResourceType\ResourceType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class LinkManagerInterface.
 *
 * @package Drupal\jsonapi
 */
interface LinkManagerInterface {

  /**
   * Gets a link for the entity.
   *
   * @param int $entity_id
   *   The entity ID to generate the link for. Note: Depending on the
   *   configuration this might be the UUID as well.
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The JSON API resource type.
   * @param array $route_parameters
   *   Parameters for the route generation.
   * @param string $key
   *   A key to build the route identifier.
   *
   * @return string
   *   The URL string.
   */
  public function getEntityLink($entity_id, ResourceType $resource_type, array $route_parameters, $key);

  /**
   * Get the full URL for a given request object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param array|null $query
   *   The query parameters to use. Leave it empty to get the query from the
   *   request object.
   *
   * @return string
   *   The full URL.
   */
  public function getRequestLink(Request $request, $query = NULL);

  /**
   * Get the full URL for a given request object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param array $link_context
   *   An associative array with extra data to build the links.
   *
   * @throws \Drupal\jsonapi\Error\SerializableHttpException
   *   When the offset and size are invalid.
   *
   * @return string
   *   The full URL.
   */
  public function getPagerLinks(Request $request, array $link_context = []);

}
