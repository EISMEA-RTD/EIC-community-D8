<?php

namespace Drupal\eic_webservices\Plugin\Validation\Constraint;

use Drupal\address\Plugin\Validation\Constraint\AddressFormatConstraintValidator as ExternalValidator;
use Drupal\eic_webservices\Utility\WsRestHelper;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Validates the address format constraint.
 */
class AddressFormatConstraintValidator extends ExternalValidator implements ContainerInjectionInterface {

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * The signature is written to match address 2.x, which declares
   * validate(mixed $value, Constraint $constraint): void. It stays compatible
   * with address 1.x, whose parent declares no parameter or return types:
   * PHP allows a child to add "mixed" to an untyped parameter and to add a
   * return type where the parent declares none. Without this, loading the
   * class under address 2.x is a fatal incompatible-declaration error on
   * every address validation, including SMED REST writes.
   */
  public function validate(mixed $value, Constraint $constraint): void {
    // If request is coming from SMED, we avoid the validation.
    $current_request = $this->requestStack->getCurrentRequest();
    if (WsRestHelper::isSmedRestRequest($current_request)) {
      return;
    }

    parent::validate($value, $constraint);
  }

}
