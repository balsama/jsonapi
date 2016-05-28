<?php

namespace Drupal\Tests\jsonapi\Kernel\Resource;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\jsonapi\EntityCollection;
use Drupal\jsonapi\Resource\DocumentWrapper;
use Drupal\jsonapi\Resource\EntityResource;
use Drupal\jsonapi\Routing\Param\Filter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class EntityResourceTest.
 *
 * @package Drupal\Tests\jsonapi\Kernel\Resource
 *
 * @coversDefaultClass \Drupal\jsonapi\Resource\EntityResource
 * @group jsonapi
 */
class EntityResourceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'jsonapi',
    'rest',
    'serialization',
    'system',
    'user',
  ];

  /**
   * The entity resource under test.
   *
   * @var \Drupal\jsonapi\Resource\EntityResource
   */
  protected $entityResource;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    // Add the entity schemas.
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    // Add the additional table schemas.
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    NodeType::create([
      'type' => 'lorem',
    ])->save();
    $type = NodeType::create([
      'type' => 'article',
    ]);
    $type->save();
    $this->user = User::create([
      'name' => 'user1',
      'mail' => 'user@localhost',
      'status' => 1,
    ]);
    $this->user->save();
    $this->node = Node::create([
      'title' => 'dummy_title',
      'type' => 'article',
      'uid' => $this->user->id(),
    ]);

    $this->node->save();

    // Give anonymous users permission to view user profiles, so that we can
    // verify the cache tags of cached versions of user profile pages.
    Role::create([
      'id' => RoleInterface::ANONYMOUS_ID,
      'permissions' => [
        'access user profiles',
        'access content',
      ],
    ])->save();

    $this->entityResource = new EntityResource(
      $this->container->get('jsonapi.resource.manager')->get('node', 'article'),
      $this->container->get('entity_type.manager'),
      $this->container->get('jsonapi.query_builder'),
      $this->container->get('entity_field.manager'),
      $this->container->get('jsonapi.current_context')
    );

  }


  /**
   * @covers ::getIndividual
   */
  public function testGetIndividual() {
    $response = $this->entityResource->getIndividual($this->node);
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertEquals(1, $response->getResponseData()->getData()->id());
    $this->assertSame('node:1', $response->getCacheableMetadata()->getCacheTags()[0]);
  }

  /**
   * @covers ::getIndividual
   * @expectedException \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  public function testGetIndividualDenied() {
    $role = Role::load(RoleInterface::ANONYMOUS_ID);
    $role->revokePermission('access content');
    $role->save();
    $this->entityResource->getIndividual($this->node);
  }

  /**
   * @covers ::getCollection
   */
  public function testGetCollection() {
    // Fake the request.
    $request = $this->prophesize(Request::class);
    $params = $this->prophesize(ParameterBag::class);
    $params->get('_route_params')->willReturn(['_json_api_params' => []]);
    $request->attributes = $params->reveal();
    $params->get('_json_api_params')->willReturn([]);

    // Get the response.
    $response = $this->entityResource->getCollection($request->reveal());

    // Assertions.
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertInstanceOf(EntityCollection::class, $response->getResponseData()->getData());
    $this->assertEquals(1, $response->getResponseData()->getData()->getIterator()->current()->id());
    $this->assertEquals(['node:1', 'node_list'], $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * @covers ::getCollection
   */
  public function testGetFilteredCollection() {
    // Fake the request.
    $request = $this->prophesize(Request::class);
    $params = $this->prophesize(ParameterBag::class);
    $field_manager = $this->container->get('entity_field.manager');
    $filter = new Filter(['type' => ['value' => 'article']], 'node_type', $field_manager);
    $params->get('_route_params')->willReturn([
      '_json_api_params' => [
        'filter' => $filter,
      ],
    ]);
    $params->get('_json_api_params')->willReturn([
      'filter' => $filter,
    ]);
    $request->attributes = $params->reveal();

    // Get the entity resource.
    $entity_resource = new EntityResource(
      $this->container->get('jsonapi.resource.manager')->get('node_type', 'node_type'),
      $this->container->get('entity_type.manager'),
      $this->container->get('jsonapi.query_builder'),
      $field_manager,
      $this->container->get('jsonapi.current_context')
    );

    // Get the response.
    $response = $entity_resource->getCollection($request->reveal());

    // Assertions.
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertInstanceOf(EntityCollection::class, $response->getResponseData()->getData());
    $this->assertCount(1, $response->getResponseData()->getData());
    $this->assertEquals(['config:node.type.article', 'config:node_type_list'], $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * @covers ::getCollection
   */
  public function testGetEmptyCollection() {
    // Fake the request.
    $request = $this->prophesize(Request::class);
    $params = $this->prophesize(ParameterBag::class);
    $filter = new Filter(
      ['uuid' => ['value' => 'invalid']],
      'node',
      $this->container->get('entity_field.manager')
    );
    $params->get('_route_params')->willReturn([
      '_json_api_params' => [
        'filter' => $filter,
      ],
    ]);
    $params->get('_json_api_params')->willReturn([
      'filter' => $filter,
    ]);
    $request->attributes = $params->reveal();

    // Get the response.
    $response = $this->entityResource->getCollection($request->reveal());

    // Assertions.
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertInstanceOf(EntityCollection::class, $response->getResponseData()->getData());
    $this->assertEquals(0, $response->getResponseData()->getData()->count());
    $this->assertEquals(['node_list'], $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * @covers ::getRelated
   */
  public function testGetRelated() {
    // to-one relationship.
    $response = $this->entityResource->getRelated($this->node, 'uid');
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertInstanceOf(User::class, $response->getResponseData()
      ->getData());
    $this->assertEquals(1, $response->getResponseData()->getData()->id());
    $this->assertSame('user:1', $response->getCacheableMetadata()->getCacheTags()[0]);

    // to-many relationship.
    $response = $this->entityResource->getRelated($this->user, 'roles');
    $this->assertInstanceOf(DocumentWrapper::class, $response
      ->getResponseData());
    $this->assertInstanceOf(EntityCollection::class, $response
      ->getResponseData()
      ->getData());
    $this->assertEquals(['config:user_role_list'], $response
      ->getCacheableMetadata()
      ->getCacheTags());
  }

  /**
   * @covers ::getRelationship
   */
  public function testGetRelationship() {
    // to-one relationship.
    $response = $this->entityResource->getRelationship($this->node, 'uid');
    $this->assertInstanceOf(DocumentWrapper::class, $response->getResponseData());
    $this->assertInstanceOf(
      EntityReferenceFieldItemListInterface::class,
      $response->getResponseData()->getData()
    );
    $this->assertEquals(1, $response
      ->getResponseData()
      ->getData()
      ->getEntity()
      ->id()
    );
    $this->assertEquals('node', $response
      ->getResponseData()
      ->getData()
      ->getEntity()
      ->getEntityTypeId()
    );
    $this->assertSame('node:1', $response->getCacheableMetadata()->getCacheTags()[0]);
  }

}