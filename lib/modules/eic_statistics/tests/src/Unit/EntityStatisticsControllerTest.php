<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_statistics\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\eic_statistics\Controller\EntityStatisticsController;
use Drupal\eic_statistics\StatisticsHelper;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests that the statistics of a node are served to who may view the node.
 *
 * The endpoint answers with the views, likes, follows, downloads and comments
 * of a node. A node that the caller may not view, such as a resource, answers
 * as a node that does not exist, so that the endpoint is no way to find out
 * which restricted content exists.
 */
#[Group('eic_statistics')]
class EntityStatisticsControllerTest extends UnitTestCase {

  /**
   * The statistics of the node of the tests.
   */
  const STATISTICS = ['views' => 12, 'likes' => 3];

  /**
   * The statistics helper.
   */
  protected StatisticsHelper|MockObject $helper;

  /**
   * The storage of the nodes.
   */
  protected EntityStorageInterface|MockObject $storage;

  /**
   * The controller under test.
   */
  protected EntityStatisticsController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->storage = $this->createMock(EntityStorageInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('node')->willReturn($this->storage);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    \Drupal::setContainer($container);

    $this->helper = $this->createMock(StatisticsHelper::class);
    $this->controller = new EntityStatisticsController($this->helper);
  }

  /**
   * Tests that the statistics of a node that may be viewed are served.
   */
  public function testViewableNodeGetsItsStatistics(): void {
    $node = $this->node('resource', TRUE);
    $this->storage->method('load')->with(144)->willReturn($node);
    $this->helper->expects($this->once())->method('getEntityStatistics')->with($node)->willReturn(self::STATISTICS);

    $response = $this->controller->getStatistics($this->request('resource', '144'));

    $this->assertSame(self::STATISTICS, json_decode($response->getContent(), TRUE));
  }

  /**
   * Tests that a node that may not be viewed is answered as a missing node.
   *
   * The response is the same, body and status, so that the endpoint tells
   * nothing about the node, and no statistics are read.
   */
  public function testNodeThatMayNotBeViewedIsAnsweredAsMissing(): void {
    $this->storage->method('load')->willReturnMap([
      [144, $this->node('resource', FALSE)],
      [999, NULL],
    ]);
    $this->helper->expects($this->never())->method('getEntityStatistics');

    $forbidden = $this->controller->getStatistics($this->request('resource', '144'));
    $missing = $this->controller->getStatistics($this->request('resource', '999'));

    $this->assertSame('[]', $forbidden->getContent());
    $this->assertSame($missing->getContent(), $forbidden->getContent());
    $this->assertSame($missing->getStatusCode(), $forbidden->getStatusCode());
  }

  /**
   * Tests that a node of another bundle is answered as a missing node.
   */
  public function testNodeOfAnotherBundleIsAnsweredAsMissing(): void {
    $this->storage->method('load')->with(144)->willReturn($this->node('page', TRUE));
    $this->helper->expects($this->never())->method('getEntityStatistics');

    $response = $this->controller->getStatistics($this->request('resource', '144'));

    $this->assertSame('[]', $response->getContent());
  }

  /**
   * Tests that the request needs the bundle and the id of the node.
   */
  public function testRequestWithoutBundleOrIdIsNotFound(): void {
    $this->helper->expects($this->never())->method('getEntityStatistics');

    $this->expectException(NotFoundHttpException::class);
    $this->controller->getStatistics(new Request(['bundle' => 'resource']));
  }

  /**
   * Mocks a node.
   */
  protected function node(string $bundle, bool $viewable): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('access')->willReturnCallback(
      fn (string $operation) => $operation === 'view' && $viewable
    );
    return $node;
  }

  /**
   * Builds a request for the statistics of a node.
   */
  protected function request(string $bundle, string $id): Request {
    return new Request(['bundle' => $bundle, 'entityId' => $id]);
  }

}
