<?php

namespace Drupal\eic_helper\StackMiddleware;

use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Class HeaderMapper
 * This class is intended to map non-standard reverse proxy headers
 * to X-Forwarded-* headers.
 * We then let the job to the ReverseProxyMiddleware from core.
 *
 * @package Drupal\eic_helper\StackMiddleware
 */
class HeaderMapper implements HttpKernelInterface {

  /**
   * Name of the settings where the mapping is stored.
   */
  const HEADER_MAPPING_SETTING = 'reverse_proxy_header_mapping';

  /**
   * List of supported headers this class maps values towards.
   */
  private const TRUSTED_HEADERS = [
    Request::HEADER_X_FORWARDED_FOR => 'X_FORWARDED_FOR',
    Request::HEADER_X_FORWARDED_HOST => 'X_FORWARDED_HOST',
    Request::HEADER_X_FORWARDED_PROTO => 'X_FORWARDED_PROTO',
    Request::HEADER_X_FORWARDED_PORT => 'X_FORWARDED_PORT',
  ];

  /**
   * Settings key holding hosts whose request scheme must be forced to HTTPS.
   *
   * Populated per-environment in settings.php (typically from an env var).
   * Empty / unset = middleware no-ops, suitable for local HTTP development.
   *
   * Workaround for the EC reverse proxy chain reporting X-Forwarded-Proto=http
   * to the pod despite TLS terminating at CloudFront. Remove once the upstream
   * proxy forwards viewer scheme correctly.
   */
  public const FORCE_HTTPS_HOSTS_SETTING = 'eic_helper.force_https_hosts';

  /**
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected HttpKernelInterface $httpKernel;

  /**
   * @var \Drupal\Core\Site\Settings
   */
  protected Settings $settings;

  /**
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   * @param \Drupal\Core\Site\Settings $settings
   */
  public function __construct(
    HttpKernelInterface $http_kernel,
    Settings $settings
  ) {
    $this->settings = $settings;
    $this->httpKernel = $http_kernel;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(
    Request $request,
    $type = self::MAIN_REQUEST,
    $catch = TRUE
  ): Response {
    $this->forceHttpsForKnownHosts($request);

    if (
      $this->settings->get('reverse_proxy') !== FALSE
      && !empty($this->settings->get(self::HEADER_MAPPING_SETTING))
    ) {
      $this->encodeRequestUri($request);
      $this->mapHeaders($request);
    }

    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Force HTTPS scheme on the live Request for hostnames that are HTTPS-only
   * at the edge but receive an http:// X-Forwarded-Proto from the EC proxy.
   *
   * Mutates $request->server (HTTPS, SERVER_PORT) and the X-Forwarded-Proto
   * / X-Forwarded-Port headers so both Drupal's direct scheme detection and
   * core's ReverseProxyMiddleware (priority 300) see https.
   *
   * Must run on the live Request object — mutating $_SERVER from
   * settings.k8s.php is too late, since Symfony's Request snapshots $_SERVER
   * via createFromGlobals() before settings load.
   */
  protected function forceHttpsForKnownHosts(Request $request): void {
    $hosts = $this->settings->get(self::FORCE_HTTPS_HOSTS_SETTING, []);
    if (empty($hosts)) {
      return;
    }
    // Host headers are case-insensitive per RFC 7230; normalize both sides so
    // a misconfigured DDC_FORCE_HTTPS_HOSTS=Foo.Example.Org doesn't fail open.
    $host = strtolower($request->getHost());
    $hosts = array_map('strtolower', $hosts);
    if (!in_array($host, $hosts, TRUE)) {
      return;
    }
    // Set both server vars and forwarded headers: the server pins handle the
    // case where reverse_proxy trust is off (current dev state); the headers
    // win once reverse_proxy = TRUE and ReverseProxyMiddleware takes over.
    $request->server->set('HTTPS', 'on');
    $request->server->set('SERVER_PORT', 443);
    $request->headers->set('X-Forwarded-Proto', 'https');
    $request->headers->set('X-Forwarded-Port', '443');
  }

  /**
   * The platform relies on urls with special characters (e.g: create/group_node:book).
   * The reverse proxy might send a not encoded value for REQUEST_URI.
   * This method is intended to encode such values in any case.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return void
   */
  protected function encodeRequestUri(Request $request) {
    if (strpos($request->server->get('REQUEST_URI'), ':') !== FALSE) {
      $request->server->set('REQUEST_URI', str_replace(':', urlencode(':'), $request->server->get('REQUEST_URI')));
    }
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   */
  protected function mapHeaders(Request $request) {
    $supported_headers = Settings::get(self::HEADER_MAPPING_SETTING);
    foreach ($supported_headers as $to => $from) {
      if (!self::TRUSTED_HEADERS[$to] || !$request->headers->has($from)) {
        continue;
      }

      $request->headers->set(
        self::TRUSTED_HEADERS[$to],
        $request->headers->get($from)
      );
    }
  }

}
