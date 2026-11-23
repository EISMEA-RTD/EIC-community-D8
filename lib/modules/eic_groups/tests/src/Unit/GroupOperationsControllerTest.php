<?php

declare(strict_types = 1);

namespace Drupal\Tests\eic_groups\Unit;

use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\eic_groups\Controller\GroupOperationsController;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests that the like endpoint calls the two flag actions only.
 *
 * The action is a part of the URL. It is the name of a method of the flag
 * service, and the service has methods that delete every flagging of a flag,
 * of a user or of an entity: whatever the URL says, only flag and unflag are
 * called.
 */
#[Group('eic_groups')]
class GroupOperationsControllerTest extends UnitTestCase {

  /**
   * The flag service.
   */
  protected FlagServiceInterface|MockObject $flagService;

  /**
   * The controller under test.
   */
  protected GroupOperationsController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->flagService = $this->createMock(FlagServiceInterface::class);
    $this->controller = new GroupOperationsController(
      $this->createMock(RedirectDestinationInterface::class),
      $this->createMock(RequestStack::class),
      $this->flagService,
    );
  }

  /**
   * Tests that an action that is not flag or unflag calls nothing.
   *
   * The flag service is a double that fails on every call.
   */
  #[DataProvider('foreignActions')]
  public function testForeignActionCallsNothing(string $action): void {
    $this->flagService->expects($this->never())->method($this->anything());

    $this->expectException(NotFoundHttpException::class);
    $this->controller->likeContent(new Request(), $this->createMock(GroupInterface::class), $action, $this->createMock(NodeInterface::class));
  }

  /**
   * Provides actions that are methods of the flag service, and others.
   */
  public static function foreignActions(): array {
    return [
      'unflagAllByFlag' => ['unflagAllByFlag'],
      'unflagAllByEntity' => ['unflagAllByEntity'],
      'unflagAllByUser' => ['unflagAllByUser'],
      'getFlagById' => ['getFlagById'],
      'upper case' => ['Flag'],
      'empty' => [''],
    ];
  }

  /**
   * Tests that flag and unflag are called on the like flag and the node.
   */
  #[DataProvider('knownActions')]
  public function testKnownActionIsCalled(string $action): void {
    $flag = $this->createMock(FlagInterface::class);
    $node = $this->createMock(NodeInterface::class);
    $this->flagService->method('getFlagById')->willReturn($flag);
    $this->flagService->expects($this->once())->method($action)->with($flag, $node);

    $response = $this->controller->likeContent(new Request(), $this->createMock(GroupInterface::class), $action, $node);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Provides the actions that are served.
   */
  public static function knownActions(): array {
    return ['flag' => ['flag'], 'unflag' => ['unflag']];
  }

}
