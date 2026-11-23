<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_groups\Unit;

use Drupal\comment\CommentInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\eic_groups\Controller\DiscussionController;
use Drupal\eic_groups\EICGroupsHelper;
use Drupal\eic_moderation\ModerationHelper;
use Drupal\eic_search\Service\SolrDocumentProcessor;
use Drupal\flag\FlagService;
use Drupal\node\NodeInterface;
use Drupal\oec_group_comments\GroupPermissionChecker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests that the comments of a discussion are served to who may view it.
 *
 * The endpoint takes the id of any node. It lists and counts the comments of a
 * discussion that the caller may view, and answers as for a node that does not
 * exist for the other nodes, restricted resources included, so that it is no
 * way to read or to find out about them.
 */
#[Group('eic_groups')]
class DiscussionControllerFetchCommentsTest extends UnitTestCase {

  /**
   * The storage of the nodes.
   */
  protected EntityStorageInterface|MockObject $nodeStorage;

  /**
   * The storage of the comments.
   */
  protected EntityStorageInterface|MockObject $commentStorage;

  /**
   * The conditions of every comment query built, in order.
   *
   * @var array[]
   */
  protected array $queries = [];

  /**
   * The controller under test.
   */
  protected DiscussionController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->commentStorage = $this->createMock(EntityStorageInterface::class);
    $this->commentStorage->method('getQuery')->willReturnCallback(fn () => $this->query());
    $this->commentStorage->method('loadMultiple')->willReturn([]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnMap([
      ['node', $this->nodeStorage],
      ['comment', $this->commentStorage],
    ]);

    $this->controller = new DiscussionController(
      $entity_type_manager,
      $this->createMock(FlagService::class),
      $this->createMock(GroupPermissionChecker::class),
      $this->createMock(DateFormatter::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(SolrDocumentProcessor::class),
      $this->createMock(ModerationHelper::class),
      $this->createMock(EICGroupsHelper::class),
    );
  }

  /**
   * Tests that a node that is not a discussion that may be viewed is not found.
   *
   * A missing node, a node of another bundle and a discussion that the caller
   * may not view all answer the same, and no comment is counted or listed.
   */
  #[DataProvider('nodesThatAreNotServed')]
  public function testNodeThatIsNotServedIsNotFound(?string $bundle, bool $viewable): void {
    $this->nodeStorage->method('load')->with('144')->willReturn($bundle === NULL ? NULL : $this->node($bundle, $viewable));
    $this->commentStorage->expects($this->never())->method('load');

    try {
      $this->controller->fetchComments(new Request(), '144');
      $this->fail('The node is not found.');
    }
    catch (NotFoundHttpException) {
    }
    $this->assertSame([], $this->queries, 'No comment is counted or listed.');
  }

  /**
   * Provides the nodes that the endpoint does not serve.
   */
  public static function nodesThatAreNotServed(): array {
    return [
      'missing node' => [NULL, TRUE],
      'resource that may be viewed' => ['resource', TRUE],
      'resource that may not be viewed' => ['resource', FALSE],
      'discussion that may not be viewed' => ['discussion', FALSE],
    ];
  }

  /**
   * Tests that the comments of a discussion that may be viewed are listed.
   */
  public function testDiscussionThatMayBeViewedIsServed(): void {
    $this->nodeStorage->method('load')->with('144')->willReturn($this->node('discussion', TRUE));

    $response = $this->controller->fetchComments(new Request(), '144');

    $this->assertSame(['comments' => [], 'total' => 0, 'total_loaded' => 3], json_decode($response->getContent(), TRUE));
    $this->assertCount(2, $this->queries, 'The comments are listed and counted.');
    foreach ($this->queries as $conditions) {
      $this->assertContains(['entity_id', '144'], array_map(fn (array $c) => array_slice($c, 0, 2), $conditions));
    }
  }

  /**
   * Tests that the replies of a comment of another node are not listed.
   *
   * The parent id selects the replies. They are the replies on this
   * discussion only, so that the id of a comment on a restricted node does not
   * list its replies through a discussion that the caller may view.
   */
  public function testRepliesAreOfThisDiscussionOnly(): void {
    $this->nodeStorage->method('load')->with('144')->willReturn($this->node('discussion', TRUE));

    $this->controller->fetchComments(new Request(['parentId' => '77']), '144');

    $this->assertNotEmpty($this->queries);
    foreach ($this->queries as $conditions) {
      $this->assertContains(['entity_id', '144'], array_map(fn (array $c) => array_slice($c, 0, 2), $conditions));
      $this->assertContains(['pid', '77'], array_map(fn (array $c) => array_slice($c, 0, 2), $conditions));
    }
  }

  /**
   * Tests that a highlighted comment of another node is not shown.
   *
   * The comment is rendered only when it is a comment of this discussion; the
   * double fails the test if it is rendered.
   */
  public function testHighlightedCommentOfAnotherNodeIsLeftOut(): void {
    $this->nodeStorage->method('load')->with('144')->willReturn($this->node('discussion', TRUE));
    $comment = $this->createMock(CommentInterface::class);
    $comment->method('id')->willReturn(55);
    $comment->method('getCommentedEntityId')->willReturn(150);
    $this->commentStorage->method('load')->with('55')->willReturn($comment);

    $response = $this->controller->fetchComments(new Request(['highlightedComment' => '55']), '144');

    $this->assertSame([], json_decode($response->getContent(), TRUE)['comments']);
  }

  /**
   * Tests that a highlighted comment that may not be seen is left out.
   *
   * A comment of this discussion that is not published, or that the caller may
   * not view, is not pulled to the top of the list; the double fails the test
   * if it is rendered.
   */
  #[DataProvider('unseenComments')]
  public function testHighlightedCommentThatMayNotBeSeenIsLeftOut(bool $published, bool $viewable): void {
    $this->nodeStorage->method('load')->with('144')->willReturn($this->node('discussion', TRUE));
    $comment = $this->createMock(CommentInterface::class);
    $comment->method('id')->willReturn(55);
    $comment->method('getCommentedEntityId')->willReturn(144);
    $comment->method('isPublished')->willReturn($published);
    $comment->method('access')->willReturnCallback(
      fn (string $operation) => $operation === 'view' && $viewable
    );
    $this->commentStorage->method('load')->with('55')->willReturn($comment);

    $response = $this->controller->fetchComments(new Request(['highlightedComment' => '55']), '144');

    $this->assertSame([], json_decode($response->getContent(), TRUE)['comments']);
  }

  /**
   * Provides the comments that are not shown: unpublished, not viewable.
   */
  public static function unseenComments(): array {
    return [
      'unpublished' => [FALSE, TRUE],
      'not viewable' => [TRUE, FALSE],
      'neither' => [FALSE, FALSE],
    ];
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
   * Builds a comment query double that records its conditions.
   */
  protected function query(): QueryInterface {
    $index = count($this->queries);
    $this->queries[$index] = [];

    $counting = FALSE;
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'sort', 'range'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('count')->willReturnCallback(
      function () use (&$counting, $query) {
        $counting = TRUE;
        return $query;
      }
    );
    $query->method('condition')->willReturnCallback(
      function (...$arguments) use ($index, $query) {
        $this->queries[$index][] = $arguments;
        return $query;
      }
    );
    $query->method('execute')->willReturnCallback(function () use (&$counting) {
      return $counting ? 0 : [];
    });
    return $query;
  }

}
