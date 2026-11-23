<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_groups\Unit;

use Drupal\comment\CommentInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\eic_groups\Controller\DiscussionController;
use Drupal\eic_groups\EICGroupsHelper;
use Drupal\eic_moderation\ModerationHelper;
use Drupal\eic_search\Service\SolrDocumentProcessor;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagService;
use Drupal\node\NodeInterface;
use Drupal\oec_group_comments\GroupPermissionChecker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the like of a comment through the flag comment endpoint.
 *
 * The type of the flag action is a part of the URL and the name of a method of
 * the flag service, whose other methods delete every flagging of a flag or of
 * a user: only flag and unflag are called. The access of the flag is a result
 * object, which says no through isAllowed().
 */
#[Group('eic_groups')]
class DiscussionControllerFlagCommentTest extends UnitTestCase {

  /**
   * The flag service.
   */
  protected FlagService|MockObject $flagService;

  /**
   * The controller under test.
   */
  protected DiscussionController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $comment = $this->createMock(CommentInterface::class);
    $node = $this->createMock(NodeInterface::class);
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->method('load')->willReturn($comment);
    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('load')->willReturn($node);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturnCallback(
      fn (string $class) => str_contains($class, 'Comment') ? 'comment' : 'node'
    );
    $entity_type_manager->method('getStorage')->willReturnMap([
      ['comment', $comment_storage],
      ['node', $node_storage],
    ]);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $contexts_manager = $this->createMock(CacheContextsManager::class);
    $contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('entity_type.repository', $entity_type_repository);
    $container->set('current_user', $account);
    $container->set('cache_contexts_manager', $contexts_manager);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    $container->set('logger.factory', $logger_factory);
    \Drupal::setContainer($container);

    $this->flagService = $this->createMock(FlagService::class);
    $this->controller = new DiscussionController(
      $entity_type_manager,
      $this->flagService,
      $this->createMock(GroupPermissionChecker::class),
      $this->createMock(DateFormatter::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(SolrDocumentProcessor::class),
      $this->createMock(ModerationHelper::class),
      $this->createMock(EICGroupsHelper::class),
    );
  }

  /**
   * Tests that an action that is not flag or unflag calls nothing.
   */
  #[DataProvider('foreignActions')]
  public function testForeignActionCallsNothing(string $type): void {
    $this->flagService->expects($this->never())->method($this->anything());

    $this->expectException(NotFoundHttpException::class);
    $this->controller->flagComment($this->request(), 144, 9, 'like_comment', $type);
  }

  /**
   * Provides actions that are methods of the flag service, and others.
   */
  public static function foreignActions(): array {
    return [
      'unflagAllByFlag' => ['unflagAllByFlag'],
      'unflagAllByUser' => ['unflagAllByUser'],
      'getFlagById' => ['getFlagById'],
      'empty' => [''],
    ];
  }

  /**
   * Tests that a like that the account may not make is refused.
   */
  #[DataProvider('knownActions')]
  public function testLikeIsRefusedWhenTheFlagAccessSaysNo(string $type): void {
    $flag = $this->createMock(FlagInterface::class);
    $flag->method('actionAccess')->willReturn(AccessResult::neutral());
    $this->flagService->method('getFlagById')->willReturn($flag);
    $this->flagService->expects($this->never())->method($type);

    $response = $this->controller->flagComment($this->request(), 144, 9, 'like_comment', $type);

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Tests that a like that the account may make is made.
   */
  #[DataProvider('knownActions')]
  public function testLikeIsMadeWhenTheFlagAccessSaysYes(string $type): void {
    $flag = $this->createMock(FlagInterface::class);
    $flag->method('actionAccess')->willReturn(AccessResult::allowed());
    $this->flagService->method('getFlagById')->willReturn($flag);
    $this->flagService->expects($this->once())->method($type);

    $response = $this->controller->flagComment($this->request(), 144, 9, 'like_comment', $type);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Provides the actions that are served.
   */
  public static function knownActions(): array {
    return ['flag' => ['flag'], 'unflag' => ['unflag']];
  }

  /**
   * Builds the request that carries the text of the flag.
   */
  protected function request(): Request {
    return new Request([], [], [], [], [], [], '{"text":"a reason"}');
  }

}
